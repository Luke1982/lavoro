<?php

namespace App\Console\Commands;

use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
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

        $pending = DB::connection('central')->table('jobs')->min('available_at');
        $pending && $pending < now()->subHour()->timestamp
            ? $this->bad('oudste wachtende job is meer dan een uur oud -- draait de worker?')
            : $this->pass('wachtrij');

        $beat = Cache::get('scheduler_heartbeat');

        if (!$beat) {
            $this->skip('planner-hartslag nog nooit geschreven (nieuwe installatie, of cron draait niet)');
        } elseif ($beat < now()->subMinutes(15)->timestamp) {
            $this->bad('planner-hartslag is ouder dan 15 minuten -- cron draait niet');
        } else {
            $this->pass('planner draait');
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
     * De grens waar deze hele opzet op leunt: het account van de applicatie mag
     * geen databases van klanten kunnen maken of weggooien, en alleen de
     * provisioner mag dat wel. Dat stond tot nu toe alleen in een leesmij, en
     * een voorwaarde die nergens gecontroleerd wordt is een voorwaarde die na
     * de eerste de beste herinstallatie stilletjes weg is.
     */
    private function checkPrivileges(): void
    {
        $this->line('Rechten');

        try {
            $grants = array_map(
                fn ($row) => (string) array_values((array) $row)[0],
                DB::connection('central')->select('SHOW GRANTS FOR CURRENT_USER()'),
            );
        } catch (\Throwable $e) {
            $this->skip('kan de rechten van dit account niet opvragen: ' . $e->getMessage());

            return;
        }

        $account = DB::connection('central')->selectOne('SELECT CURRENT_USER() AS wie')->wie ?? 'onbekend';

        /**
         * Twee manieren waarop het te ruim staat: alles op alles, of rechten
         * op de databases van klanten. Beide betekenen dat een fout in de
         * webapplicatie de gegevens van een klant kan weggooien.
         */
        $all_on_everything = array_filter($grants, fn ($grant) => (bool) preg_match(
            '/GRANT (ALL PRIVILEGES|.*\bCREATE\b.*|.*\bDROP\b.*) ON \*\.\*/i', $grant
        ));

        $reaches_tenants = array_filter($grants, fn ($grant) => str_contains($grant, 'tenant')
            && !str_contains($grant, 'GRANT USAGE'));

        if ($all_on_everything || $reaches_tenants) {
            $this->bad("{$account} kan bij de databases van klanten. Alleen lavoro_provisioner hoort dat te kunnen.");

            foreach (array_merge($all_on_everything, $reaches_tenants) as $grant) {
                $this->line('       ' . mb_strimwidth($grant, 0, 120, '...'));
            }
        } else {
            $this->pass("{$account} kan geen klantdatabases maken of weggooien");
        }
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
     * Hoe de provisioner zichzelf bewijst. In productie hoort dat via de
     * socket te gaan, gebonden aan een eigen Linux-gebruiker: dan kan alleen
     * die gebruiker het, en staat er geen wachtwoord in een bestand dat de
     * webserver kan lezen.
     */
    private function checkProvisionerAccount(): void
    {
        $password = (string) config('database.connections.provisioner.password');

        try {
            $who = DB::connection('provisioner')->selectOne('SELECT CURRENT_USER() AS wie')->wie ?? '';
        } catch (\Throwable $e) {
            $password === ''
                ? $this->pass('provisioner niet bereikbaar vanaf dit account -- zo hoort het buiten de worker')
                : $this->bad('provisioner heeft een wachtwoord in de config maar is niet bereikbaar: ' . $e->getMessage());

            return;
        }

        if ($password !== '') {
            $this->bad("provisioner ({$who}) is met een wachtwoord uit de omgeving te bereiken vanaf dit"
                . ' proces. In productie hoort dit account aan een Linux-gebruiker te hangen'
                . ' (IDENTIFIED WITH auth_socket), anders kan alles wat de .env leest ook databases weggooien.');

            return;
        }

        $this->pass("provisioner ({$who}) bereikbaar zonder wachtwoord -- via de socket, zoals bedoeld");
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
