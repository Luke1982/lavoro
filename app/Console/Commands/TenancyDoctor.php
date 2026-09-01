<?php

namespace App\Console\Commands;

use App\Models\Central\IssuerSetting;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use App\Support\WorkerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Controleert wat git niet vasthoudt. Leest alleen; repareert nooit.
 * Een dokter die zelf ingrijpt is er een waarvan niemand de uitslag meer leest.
 */
class TenancyDoctor extends Command
{
    protected $signature = 'tenancy:doctor';

    protected $description = 'Controleert de tenancy-opstelling en elke tenant afzonderlijk';

    private int $failed = 0;

    private int $passed = 0;

    public function handle(): int
    {
        $this->line('Centraal');
        $this->checkCentral();

        foreach (Tenant::on('central')->orderBy('name')->get() as $tenant) {
            $this->newLine();
            $this->line($tenant->name);
            $this->checkTenant($tenant);
        }

        $this->newLine();
        $this->checkEnvironment();

        $this->newLine();
        $this->checkPrivileges();

        $this->newLine();
        $this->checkProvisioning();

        $this->newLine();
        $this->checkOrphans();

        $this->newLine();

        if ($this->failed === 0) {
            $this->info("Alles in orde ({$this->passed} controles).");

            return self::SUCCESS;
        }

        $this->error("{$this->failed} probleem(en), {$this->passed} in orde.");

        return self::FAILURE;
    }

    private function pass(string $m): void
    {
        $this->line("  <fg=green>OK</>   {$m}");
        $this->passed++;
    }

    private function bad(string $m): void
    {
        $this->line("  <fg=red>FOUT</> {$m}");
        $this->failed++;
    }

    private function skip(string $m): void
    {
        $this->line("  <fg=yellow>OVER</> {$m}");
    }

    private function checkCentral(): void
    {
        try {
            $name = DB::connection('central')->getDatabaseName();
            $this->pass("centrale verbinding: {$name}");
        } catch (\Throwable $e) {
            $this->bad('centrale verbinding: ' . $e->getMessage());
            $this->skip('overige centrale controles');

            return;
        }

        foreach (['tenants', 'user_tenant_lookups', 'sessions', 'cache', 'jobs', 'packages', 'modules'] as $table) {
            DB::connection('central')->getSchemaBuilder()->hasTable($table)
                ? $this->pass("tabel {$table}")
                : $this->bad("tabel {$table} ontbreekt -- is migrate gedraaid?");
        }

        config('session.connection') === 'central'
            ? $this->pass('SESSION_CONNECTION=central')
            : $this->bad('SESSION_CONNECTION is niet central');

        try {
            Cache::put('doctor', 1, 5);
            Cache::get('doctor') === 1 ? $this->pass('cache leest en schrijft') : $this->bad('cache schrijft niet');
        } catch (\Throwable $e) {
            $this->bad('cache: ' . $e->getMessage());
        }

        /**
         * Werk dat blijft liggen. De hartslag hieronder zegt of er een worker
         * leeft; dit zegt of hij ook vooruitkomt -- een worker die op elke job
         * stukloopt heeft wel een hartslag.
         */
        $pending = DB::connection('central')->table('jobs')->min('available_at');
        $pending && $pending < now()->subHour()->timestamp
            ? $this->bad('oudste wachtende job is meer dan een uur oud -- komt de worker vooruit?')
            : $this->pass('geen werk dat blijft liggen');

        $this->checkWorkers();

        $beat = Cache::get('scheduler_heartbeat');

        if (!$beat) {
            $this->skip('planner-hartslag nog nooit geschreven (nieuwe installatie, of cron draait niet)');
        } elseif ($beat < now()->subMinutes(15)->timestamp) {
            $this->bad('planner-hartslag is ouder dan 15 minuten -- cron draait niet');
        } else {
            $this->pass('planner draait');
        }
    }

    /**
     * Draaien de workers? Een lege wachtrij ziet er hetzelfde uit als een
     * worker die er niet is, dus tellen wat er klaarstaat zegt niets. Elke
     * worker schrijft daarom elke minuut een hartslag, ook als hij niets te
     * doen heeft.
     */
    private function checkWorkers(): void
    {
        $workers = [
            'default' => 'php artisan queue:work',
            'provisioning' => 'php artisan queue:work --queue=provisioning (als lavoro_provisioner)',
        ];

        foreach ($workers as $queue => $command) {
            $beat = WorkerHeartbeat::beatFor($queue);

            if ($beat === null) {
                $this->bad("Wachtrij '{$queue}': geen enkele hartslag. Draait '{$command}'?");

                continue;
            }

            $age = now()->timestamp - $beat;

            $age > WorkerHeartbeat::STALE_AFTER_MINUTES * 60
                ? $this->bad("Wachtrij '{$queue}': laatste hartslag "
                    . CarbonImmutable::createFromTimestamp($beat)->diffForHumans()
                    . ". De worker is gestopt. Start '{$command}'.")
                : $this->pass("worker voor '{$queue}' draait");
        }
    }

    private function checkTenant(Tenant $tenant): void
    {
        $database = $tenant->getInternal('db_name');

        $exists = DB::connection('central')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?', [$database]
        );

        $exists ? $this->pass("database {$database}") : $this->bad("database {$database} bestaat niet");

        try {
            $tenant->tenancy_db_password
                ? $this->pass('wachtwoord is te ontsleutelen')
                : $this->bad('geen MySQL-login -- half aangemaakte tenant');
        } catch (\Throwable $e) {
            $this->bad('wachtwoord niet te ontsleutelen -- is APP_KEY gewisseld?');

            return;
        }

        if (!$exists) {
            $this->skip('overige controles voor deze tenant');

            return;
        }

        try {
            tenancy()->initialize($tenant);

            $this->pass('verbinden met eigen login');

            $missing = collect(['is_plannable_state', 'is_planned_state', 'is_closed_state',
                'is_planning_cancelled_state', 'is_invoiced_state', 'is_incomplete_state'])
                ->reject(fn ($flag) => DB::table('service_order_stages')->where($flag, true)->exists());

            $missing->isEmpty()
                ? $this->pass('een fase voor elke vlag')
                : $this->bad('geen fase voor: ' . $missing->implode(', '));

            $users = User::withTrashed()->pluck('email');
            $known = DB::connection('central')->table('user_tenant_lookups')
                ->where('tenant_id', $tenant->id)->pluck('email');

            $orphan = $users->diff($known);

            $orphan->isEmpty()
                ? $this->pass("{$users->count()} gebruikers in de centrale lijst")
                : $this->bad($orphan->count() . ' gebruiker(s) zonder centrale rij -- die kunnen niet inloggen');

            foreach (['public', 'local'] as $disk) {
                $path = storage_path("tenant-{$tenant->id}/{$disk}");
                File::isDirectory($path) && is_writable($path)
                    ? $this->pass("opslag {$disk}")
                    : $this->bad("opslag {$disk} ontbreekt of is niet schrijfbaar");
            }

            tenancy()->end();
        } catch (\Throwable $e) {
            tenancy()->end();
            $this->bad('verbinden mislukt: ' . $e->getMessage());
        }
    }

    /**
     * De voorwaarden die niet in de code staan maar in de omgeving: welke
     * PHP-onderdelen er zijn, hoe de .env staat, en of er post uit kan. Stuk
     * voor stuk dingen die pas opvallen op het moment dat je ze nodig hebt.
     */
    private function checkEnvironment(): void
    {
        $this->line('Omgeving');

        foreach (['pcntl', 'posix'] as $extension) {
            extension_loaded($extension)
                ? $this->pass("PHP-onderdeel {$extension}")
                : $this->bad("PHP-onderdeel {$extension} ontbreekt -- het provisioner-commando kan"
                    . ' zichzelf dan niet verheffen en moet met sudo -u getypt worden');
        }

        filled(config('app.key'))
            ? $this->pass('APP_KEY staat ingevuld')
            : $this->bad('APP_KEY is leeg -- geen enkel wachtwoord van een klantdatabase is te lezen');

        if (app()->environment('production')) {
            config('app.debug')
                ? $this->bad('APP_DEBUG staat aan op productie -- foutpagina\'s tonen dan .env-waarden')
                : $this->pass('APP_DEBUG staat uit');
        }

        config('mail.default') === 'tenant'
            ? $this->pass('MAIL_MAILER=tenant -- elke klant verstuurt met zijn eigen instellingen')
            : $this->bad('MAIL_MAILER is ' . config('mail.default') . ' en niet "tenant". Iedereen'
                . ' verstuurt dan via dezelfde mailbox.');

        filled(config('mail.mailers.landlord.host'))
            ? $this->pass('eigen mailserver voor facturen ingesteld')
            : $this->bad('LANDLORD_MAIL_HOST is leeg -- facturen aan klanten kunnen niet verstuurd worden');

        $this->checkIssuer();
    }

    /** Zonder deze gegevens klopt er geen enkele factuur. */
    private function checkIssuer(): void
    {
        $issuer = IssuerSetting::all_values();

        $missing = collect(['name', 'address', 'postcode', 'city', 'vat_number', 'coc_number', 'iban'])
            ->reject(fn ($key) => filled($issuer[$key] ?? null));

        $missing->isEmpty()
            ? $this->pass('eigen bedrijfsgegevens voor op de factuur')
            : $this->bad('facturatiegegevens ontbreken: ' . $missing->implode(', ')
                . ' -- vul ze in bij Catalogus > Facturatie');
    }

    /**
     * De rechten van de databaseaccounts controleert scripts/tenancy/verify-mysql.sh.
     *
     * Die probeert als provisioner een database binnen en buiten de toegestane
     * namen te maken, en als applicatie een die geweigerd hoort te worden --
     * grondiger dan hiervandaan kan, want daar is root voor nodig. Hier alleen
     * de verwijzing; twee keer dezelfde controle schrijven levert twee
     * antwoorden op die uit elkaar gaan lopen. deploy.sh draait hem mee.
     */
    private function checkPrivileges(): void
    {
        $this->line('Rechten');
        $this->skip('Databaserechten: sudo scripts/tenancy/verify-mysql.sh (draait ook mee in deploy.sh)');
    }

    /**
     * Het beheerpaneel legt aanvragen neer die alleen de provisioner-worker kan
     * uitvoeren. Draait die niet, dan blijft een aanvraag stilletjes staan en
     * lijkt het paneel kapot. Dit is de plek waar dat opvalt.
     */
    private function checkProvisioning(): void
    {
        $this->line('Provisioning');

        $requests = TenantProvisioningRequest::on('central')
            ->whereIn('status', ['queued', 'running'])
            ->get();

        $stuck = $requests->filter(fn ($request) => $request->created_at?->lt(now()->subMinutes(15)));

        if ($stuck->isNotEmpty()) {
            $this->bad($stuck->count() . ' aanvraag(en) staan langer dan een kwartier stil. Draait'
                . ' "php artisan queue:work --queue=provisioning" als lavoro_provisioner?');
        } elseif ($requests->isNotEmpty()) {
            $this->pass($requests->count() . ' aanvraag(en) onderweg.');
        } else {
            /**
             * Geen aanvragen betekent niet dat de worker draait -- dat is
             * alleen te zien aan werk dat af is gekomen. Zonder dat is dit pad
             * onbewezen en niet in orde.
             */
            $done = TenantProvisioningRequest::on('central')
                ->where('status', 'done')->exists();

            $done
                ? $this->pass('Geen aanvragen in de wacht; de worker heeft eerder werk afgerond.')
                : $this->skip('Geen aanvragen in de wacht, en er is er nog nooit een afgerond -- of de'
                    . ' worker draait is hiermee niet vast te stellen.');
        }

        $failed = TenantProvisioningRequest::on('central')
            ->where('status', 'failed')->count();

        $failed
            ? $this->bad($failed . ' mislukte aanvraag(en); zie het beheerpaneel voor de reden.')
            : $this->pass('Geen mislukte aanvragen.');

        $this->checkProvisionerAccount();
    }

    /**
     * Het account dat databases van klanten mag maken en weggooien.
     *
     * Twee losse vragen, want ze kunnen los van elkaar misgaan: bestaat het
     * MySQL-account, en hangt het aan een Linux-gebruiker in plaats van aan
     * een wachtwoord in een bestand.
     */
    private function checkProvisionerAccount(): void
    {
        $username = (string) config('database.connections.provisioner.username');
        $password = (string) config('database.connections.provisioner.password');

        $reachable = true;
        $who = $username;

        try {
            $who = DB::connection('provisioner')->selectOne('SELECT CURRENT_USER() AS wie')->wie ?? $username;
        } catch (\Throwable $e) {
            $reachable = false;
        }

        if ($reachable) {
            $this->pass("MySQL-account {$who} bestaat en werkt");
        } elseif ($password !== '') {
            $this->bad("MySQL-account {$username} logt niet in met het wachtwoord uit de .env."
                . ' Bestaat het account wel, en klopt DB_PROVISIONER_PASSWORD?');
        } else {
            /**
             * Niet kunnen inloggen bewijst niets. Een account dat aan een
             * Linux-gebruiker hangt hoort hier te weigeren, en een account dat
             * helemaal niet bestaat doet precies hetzelfde. Dit dus niet
             * goedkeuren.
             */
            $this->skip("Of het MySQL-account {$username} bestaat is hiervandaan niet te zien."
                . " Draai 'sudo -u {$username} php artisan tenancy:doctor' om het te controleren.");
        }

        if ($password !== '') {
            $this->bad("Het wachtwoord van {$username} staat in de .env. Wie dat bestand kan lezen --"
                . ' de webserver ook -- kan daarmee de database van elke klant weggooien.');
        }

        $this->checkProvisionerLinuxUser($username, $password);
        $this->checkElevation($username);

    }

    /**
     * Hoort het account aan een Linux-gebruiker te hangen (geen wachtwoord),
     * dan moet die gebruiker er ook zijn. Zonder hem kan niemand meer
     * inloggen en staat het aanmaken van klanten stil.
     */
    /**
     * Kunnen de tenant-commando's zichzelf verheffen?
     *
     * Zonder de sudo-regel werkt alles nog, maar dan geeft
     * "php artisan tenant:create" geen klant terug maar een uitleg over welk
     * commando je had moeten typen. Beter hier te lezen dan daar.
     */
    private function checkElevation(string $username): void
    {
        if (ProvisionerConnection::linuxUser() === $username) {
            $this->pass("draait al als {$username}; verheffen is niet nodig");

            return;
        }

        ProvisionerConnection::canElevate()
            ? $this->pass("kan zonder wachtwoord {$username} worden; commando's verheffen zichzelf")
            : $this->skip("Kan niet zonder wachtwoord {$username} worden. Tenant-commando's moeten dan"
                . " met 'sudo -u {$username} php artisan ...' getypt worden. Wil je dat niet, installeer"
                . ' dan /etc/sudoers.d/lavoro-admin (plan, taak 2 stap 2b).');
    }

    private function checkProvisionerLinuxUser(string $username, string $password): void
    {
        if (!function_exists('posix_getpwnam')) {
            $this->skip("Kan niet nakijken of de Linux-gebruiker {$username} bestaat (posix ontbreekt).");

            return;
        }

        if (posix_getpwnam($username) !== false) {
            $this->pass("Linux-gebruiker {$username} bestaat");

            return;
        }

        $password === ''
            ? $this->bad("Linux-gebruiker {$username} bestaat niet, terwijl er geen wachtwoord is ingesteld."
                . ' Zo kan niemand inloggen en kan er geen klant aangemaakt worden.')
            : $this->bad("Linux-gebruiker {$username} bestaat niet. Maak hem aan en koppel het"
                . ' MySQL-account eraan, dan kan het wachtwoord uit de .env weg.'
                . ' Stappen staan in docs/tenancy-bediening.md.');
    }

    private function checkOrphans(): void
    {
        $this->line('Wezen');

        $ids = Tenant::on('central')->pluck('id');

        $stale = DB::connection('central')->table('user_tenant_lookups')
            ->whereNotIn('tenant_id', $ids)->count();

        $stale === 0 ? $this->pass('geen verwijzingen naar verdwenen tenants')
            : $this->bad("{$stale} rij(en) in user_tenant_lookups wijzen naar een tenant die niet meer bestaat");

        $prefix = config('tenancy.database.prefix');

        $databases = collect(DB::connection('central')->select(
            'SELECT SCHEMA_NAME AS n FROM information_schema.schemata WHERE SCHEMA_NAME LIKE ?', [$prefix . '%']
        ))->pluck('n');

        $known = Tenant::on('central')->get()->map(fn ($t) => $t->getInternal('db_name'));
        $unknown = $databases->diff($known);

        $unknown->isEmpty() ? $this->pass('geen databases zonder tenant')
            : $this->bad('database zonder tenant: ' . $unknown->implode(', '));
    }
}
