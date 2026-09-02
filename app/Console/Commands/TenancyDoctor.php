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

        if (!$this->checkCentral()) {
            $this->newLine();
            $this->error('Zonder de centrale database valt er verder niets te controleren.');

            return self::FAILURE;
        }

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

    /**
     * @return bool of de centrale database bereikbaar is; zo niet, dan heeft
     *              geen enkele controle daarna nog zin
     */
    private function checkCentral(): bool
    {
        try {
            $name = DB::connection('central')->getDatabaseName();

            /**
             * getDatabaseName() leest alleen de instellingen en opent geen
             * verbinding. Zonder een echte vraag aan de server meldde de doctor
             * hier "in orde" terwijl er niets draaide, en klapte de controle
             * daarna eruit met een stacktrace in plaats van een melding.
             */
            DB::connection('central')->select('SELECT 1');

            $this->pass("centrale verbinding: {$name}");
        } catch (\Throwable $e) {
            $this->bad('centrale verbinding: ' . $e->getMessage());

            return false;
        }

        /**
         * De centrale verbinding staat in de instellingen vast op TCP, maar
         * migraties, het aanmaken van klanten en de sjabloonverbinding lopen
         * over de standaardverbinding. Die kan stuk zijn terwijl de centrale
         * het gewoon doet, en dan meldde de doctor niets terwijl migrate
         * weigerde.
         */
        $template = config('tenancy.database.template_tenant_connection', 'mysql');

        try {
            DB::connection($template)->select('SELECT 1');
            $this->pass("verbinding '{$template}' (migraties en provisioning)");
        } catch (\Throwable $e) {
            $socket = (string) config("database.connections.{$template}.unix_socket");
            $host = (string) config("database.connections.{$template}.host");

            $socket === ''
                ? $this->bad('standaardverbinding: ' . $e->getMessage())
                : $this->bad("De standaardverbinding loopt over de socket {$socket}. MySQL ziet het"
                    . " account daardoor als 'localhost' en niet als {$host}, en daar bestaat het"
                    . ' niet. Haal DB_SOCKET uit .env.');
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

        return true;
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

        foreach (['pcntl', 'posix', 'pdo_mysql'] as $extension) {
            extension_loaded($extension)
                ? $this->pass("PHP-onderdeel {$extension}")
                : $this->bad("PHP-onderdeel {$extension} ontbreekt -- het provisioner-commando kan"
                    . ' zichzelf dan niet verheffen en moet met sudo -u getypt worden');
        }

        /**
         * Staat exec of shell_exec in disable_functions, dan kan er niets meer
         * verheven worden -- en erger: de controles hierboven die daarop
         * leunen krijgen 'nee' terug zonder dat er iets mis is. Dan meldt de
         * doctor een probleem dat niet bestaat en verbergt hij het echte.
         */
        $blocked = array_values(array_intersect(
            ['exec', 'shell_exec', 'proc_open'],
            array_map('trim', explode(',', (string) ini_get('disable_functions'))),
        ));

        empty($blocked)
            ? $this->pass('php mag programma\'s starten')
            : $this->bad('In php.ini staat ' . implode(' en ', $blocked) . ' uit. Zonder die functies'
                . ' verheffen commando\'s zichzelf niet, en kan hierboven niet nagekeken worden of dat'
                . ' wel zou lukken -- die meldingen zeggen dan niets.');

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

        $this->checkVersions();
        $this->checkDrivers();
        $this->checkInvoiceFonts();
        $this->checkIssuer();
    }

    /**
     * De versies waar dit op gebouwd is. Een oudere PHP of database geeft
     * geen nette weigering maar een vreemde fout op een willekeurige plek.
     */
    private function checkVersions(): void
    {
        version_compare(PHP_VERSION, '8.3', '>=')
            ? $this->pass('PHP ' . PHP_VERSION)
            : $this->bad('PHP ' . PHP_VERSION . ' is te oud; 8.3 of hoger is nodig.');

        try {
            $version = (string) DB::connection('central')->selectOne('SELECT VERSION() AS v')->v;
        } catch (\Throwable) {
            return;
        }

        $number = preg_replace('/[^0-9.].*$/', '', $version);
        $minimum = str_contains(strtolower($version), 'mariadb') ? '10.11' : '8.0';

        version_compare($number, $minimum, '>=')
            ? $this->pass('database ' . $version)
            : $this->bad("Database {$version} is te oud; {$minimum} of hoger is nodig.");
    }

    /**
     * De opslagplekken die het ontwerp aanneemt.
     *
     * Staat de wachtrij op sync, dan draait provisioning in het webverzoek als
     * het account van de applicatie -- dat geen databases mag maken -- in
     * plaats van in de worker die dat wel mag. Staat de sessie niet centraal,
     * dan zoekt het inloggen zijn gebruiker in de verkeerde database.
     */
    private function checkDrivers(): void
    {
        $expected = [
            'queue.default' => ['database', 'De wachtrij staat op %s. Provisioning draait dan in het'
                . ' webverzoek als het verkeerde account in plaats van in de eigen worker.'],
            'session.driver' => ['database', 'De sessie staat op %s en hoort op database te staan;'
                . ' anders staat hij niet centraal.'],
            'cache.default' => ['database', 'De cache staat op %s en hoort op database te staan;'
                . ' de scheiding per klant hangt aan de centrale cache.'],
        ];

        foreach ($expected as $key => [$want, $complaint]) {
            $actual = config($key);

            $actual === $want
                ? $this->pass("{$key}={$want}")
                : $this->bad(sprintf($complaint, $actual));
        }
    }

    /**
     * dompdf legt de maten van een lettertype in een eigen map neer en maakt
     * die map niet aan. Ontbreekt hij, dan rolt er geen factuur uit maar een
     * foutmelding -- en dat merk je pas als je er een wilt versturen.
     */
    private function checkInvoiceFonts(): void
    {
        $directory = config('dompdf.options.font_dir', storage_path('fonts'));

        if (!is_dir($directory)) {
            $this->bad("De map {$directory} bestaat niet; facturen renderen dan niet."
                . ' Maak hem aan en geef de webserver schrijfrecht.');

            return;
        }

        is_writable($directory)
            ? $this->pass('lettertypemap voor facturen')
            : $this->bad("De map {$directory} is niet beschrijfbaar; facturen renderen dan niet.");
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
    private const PRIVILEGES_STALE_AFTER_DAYS = 30;

    /**
     * De rechten van de databaseaccounts kan dit commando niet zelf nakijken:
     * daarvoor moet je in mysql.user kunnen kijken en dat mag alleen root.
     * verify-mysql.sh kan dat wel en laat zijn uitslag achter; hier wordt die
     * gelezen.
     *
     * Een run zonder root slaat de helft over. Dat telt niet als goedkeuring,
     * anders zou 'even zonder sudo gedraaid' hier als groen vinkje eindigen.
     */
    private function checkPrivileges(): void
    {
        $this->line('Rechten');

        $advice = 'Draai: sudo scripts/tenancy/verify-mysql.sh (gebeurt ook bij elke deploy).';
        $file = storage_path('app/tenancy-privileges.json');

        $outcome = is_readable($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        if (!is_array($outcome) || !isset($outcome['checked_at'])) {
            $this->skip('De rechten van de databaseaccounts zijn hier nog nooit nagekeken.'
                . ' Dat is wat de scheiding tussen de accounts bewijst. ' . $advice);

            return;
        }

        $when = CarbonImmutable::parse($outcome['checked_at']);
        $moment = $when->diffForHumans();

        if (($outcome['failed'] ?? 0) > 0) {
            $this->bad("Bij de laatste controle ({$moment}) waren er {$outcome['failed']} probleem(en)"
                . ' met de rechten van de databaseaccounts. ' . $advice);

            return;
        }

        if (($outcome['skipped'] ?? 0) > 0) {
            $this->skip("De laatste controle ({$moment}) kon {$outcome['skipped']} punt(en) niet nakijken"
                . ' en bewijst dus niets. ' . $advice);

            return;
        }

        if ($when->isBefore(now()->subDays(self::PRIVILEGES_STALE_AFTER_DAYS))) {
            $this->skip("De rechten zijn voor het laatst nagekeken {$moment}. " . $advice);

            return;
        }

        /**
         * Controles die nog niet van toepassing waren -- er is bijvoorbeeld nog
         * geen enkele klant, dus ook geen klantaccount om na te kijken -- zijn
         * geen gat in de controle. Wel het vermelden waard, want zodra er een
         * klant is zegt een nieuwe run meer dan deze.
         */
        $pending = (int) ($outcome['not_applicable'] ?? 0);

        $this->pass("rechten van de databaseaccounts nagekeken ({$moment})"
            . ($pending > 0 ? ", op {$pending} punt(en) na die toen nog niet bestonden" : ''));
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
            $this->checkProvisionerAccountByElevating($username);
        }

        if ($password !== '') {
            $this->bad("Het wachtwoord van {$username} staat in de .env. Wie dat bestand kan lezen --"
                . ' de webserver ook -- kan daarmee de database van elke klant weggooien.');
        }

        $this->checkProvisionerLinuxUser($username, $password);
        $this->checkElevation($username);
        $this->checkProvisionerCanWriteStorage($username);
        $this->checkWebServerCanLog();

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
                . " met 'sudo -u {$username} php artisan ...' getypt worden. Wil je dat niet, draai"
                . ' dan: sudo scripts/tenancy/setup-sudoers.sh');
    }

    /**
     * De provisioning-worker maakt de mappen van een nieuwe klant aan. Mag hij
     * niet in storage/ schrijven, dan komt de klant er wel maar mislukt zijn
     * eerste upload -- een lege map die niemand mist tot dat gebeurt.
     *
     * Alleen te testen als we die gebruiker kunnen worden; anders zegt onze
     * eigen toegang niets over de zijne.
     */
    /**
     * Mag de webserver schrijven in storage/?
     *
     * De worker en de commando's draaien als het account van de installatie,
     * maar php onder de webserver draait vaak als iets anders -- 'nobody' bij
     * LiteSpeed, 'www-data' bij Apache. Kan dat account niet in storage/logs
     * schrijven, dan verdwijnt elke fout uit een webverzoek geruisloos: geen
     * pagina, geen regel, niets om op te zoeken.
     *
     * Wie dat account is valt af te lezen aan de gecompileerde sjablonen: die
     * schrijft de webserver zelf, bij de eerste pagina die hij toont.
     */
    private function checkWebServerCanLog(): void
    {
        $compiled = glob(storage_path('framework/views/*.php')) ?: [];

        if (empty($compiled)) {
            $this->skip('Als wie de webserver draait is nog niet te zien: er zijn geen gecompileerde'
                . ' sjablonen. Open een pagina en draai dit opnieuw.');

            return;
        }

        $account = $this->ownerOf($compiled[0]);
        $log = storage_path('logs/laravel.log');

        if ($account === null || !file_exists($log)) {
            $this->skip('Niet na te gaan als wie de webserver draait.');

            return;
        }

        if ($this->userCanWrite($account, $log)) {
            $this->pass("webserver draait als {$account} en kan zijn fouten opschrijven");

            return;
        }

        $this->bad("De webserver draait als {$account}, maar dat account kan niet schrijven in"
            . " {$log} (dat is van " . ($this->ownerOf($log) ?? '?') . ').'
            . ' Elke fout uit een webverzoek verdwijnt dan zonder spoor. Herstellen met:' . "\n"
            . "         sudo setfacl -R -m u:{$account}:rwX storage bootstrap/cache\n"
            . "         sudo setfacl -R -d -m u:{$account}:rwX storage bootstrap/cache");
    }

    private function ownerOf(string $path): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }

        $owner = @fileowner($path);

        return $owner === false ? null : (posix_getpwuid($owner)['name'] ?? null);
    }

    /**
     * Of een ander account ergens mag schrijven. Niet te doen met is_writable:
     * die kijkt naar het account dat dit commando draait.
     *
     * Een ACL kan schrijfrecht geven waar de rechtenbits van niets weten, dus
     * die wordt er nog naast gelegd voordat er iets gemeld wordt.
     */
    private function userCanWrite(string $account, string $path): bool
    {
        /** root trekt zich van rechtenbits niets aan. */
        if ($account === 'root') {
            return true;
        }

        $details = function_exists('posix_getpwnam') ? posix_getpwnam($account) : false;
        $stat = @stat($path);

        if ($details === false || $stat === false) {
            return true;
        }

        $mode = $stat['mode'];

        if ($stat['uid'] === $details['uid']) {
            return ($mode & 0o200) !== 0;
        }

        $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid']) : false;
        $member = $group !== false
            && ($group['gid'] === $details['gid'] || in_array($account, $group['members'], true));

        if ($member && ($mode & 0o020) !== 0) {
            return true;
        }

        if (($mode & 0o002) !== 0) {
            return true;
        }

        return $this->aclGrantsWrite($account, $path);
    }

    private function aclGrantsWrite(string $account, string $path): bool
    {
        $output = [];
        $status = 0;

        exec('getfacl -p ' . escapeshellarg($path) . ' 2>/dev/null', $output, $status);

        if ($status !== 0) {
            return false;
        }

        foreach ($output as $line) {
            if (preg_match('/^user:' . preg_quote($account, '/') . ':(.*)$/', trim($line), $found)
                && str_contains($found[1], 'w')) {
                return true;
            }
        }

        return false;
    }

    private function checkProvisionerCanWriteStorage(string $username): void
    {
        if (!ProvisionerConnection::canElevate()) {
            $this->skip("Of {$username} in storage/ mag schrijven is hiervandaan niet te zien."
                . ' Zonder dat recht mislukt de eerste upload van een nieuwe klant.');

            return;
        }

        $path = storage_path();
        $quoted = var_export($path, true);

        /**
         * Drie uitkomsten, want twee heel verschillende oorzaken zien er
         * hetzelfde uit. Rechten op storage/ zelf helpen niet als de gebruiker
         * de mappen erboven niet in mag: staat de installatie in een home-map,
         * dan staat die standaard op 0750 en komt hij niet eens tot de deur.
         * Zonder dat onderscheid stuur je iemand net zo lang setfacl op
         * storage/ herhalen tot hij het opgeeft.
         */
        $status = ProvisionerConnection::phpAsProvisioner(
            "exit(!is_dir({$quoted}) ? 2 : (is_writable({$quoted}) ? 0 : 1));"
        );

        if ($status === 0) {
            $this->pass("{$username} mag schrijven in storage/");

            return;
        }

        if ($status === 2) {
            $this->bad("{$username} komt niet eens bij {$path}; een map erboven laat hem er niet"
                . ' door. Geef hem alleen doorgang, niet meer dan dat:' . "\n"
                . collect($this->unreachableAncestors($path))
                    ->map(fn (string $directory) => "         sudo setfacl -m u:{$username}:x {$directory}")
                    ->implode("\n") . $this->setfaclHint());

            return;
        }

        $this->bad("{$username} mag niet schrijven in {$path}; de mappen van een nieuwe klant"
            . " kunnen dan niet aangemaakt worden. Geef schrijfrecht met:\n"
            . "         sudo setfacl -R -m u:{$username}:rwX {$path}\n"
            . "         sudo setfacl -R -d -m u:{$username}:rwX {$path}" . $this->setfaclHint());
    }

    /**
     * setfacl zit in een apart pakket dat lang niet overal staat. Het advies
     * hierboven levert anders 'command not found' op, en dan is de vraag wat
     * je fout deed in plaats van wat je moet installeren.
     */
    private function setfaclHint(): string
    {
        $found = trim((string) shell_exec('command -v setfacl 2>/dev/null'));

        return $found === '' ? "\n" . '         (setfacl zit in het pakket acl: apt install acl)' : '';
    }

    /**
     * De mappen tussen / en het pad waar de provisioner niet doorheen komt.
     * Alleen die waar hij nu geen doorgang heeft, zodat er niet meer opengezet
     * wordt dan nodig.
     */
    private function unreachableAncestors(string $path): array
    {
        $blocked = [];
        $directory = dirname($path);

        while ($directory !== '/' && $directory !== '.' && $directory !== '') {
            $reachable = ProvisionerConnection::phpAsProvisioner(
                'exit(is_executable(' . var_export($directory, true) . ') ? 0 : 1);'
            );

            if ($reachable !== 0) {
                array_unshift($blocked, $directory);
            }

            $directory = dirname($directory);
        }

        return $blocked ?: [dirname($path)];
    }

    /**
     * Vanaf dit account weigert het provisioner-account altijd, want het hangt
     * aan een Linux-gebruiker. Dat zegt dus niets over of het bestaat.
     *
     * Mogen we die gebruiker worden, dan is het wel te zien: dan doen we van
     * daaruit precies wat het provisioneren straks ook doet -- via de socket
     * naar binnen, zonder wachtwoord.
     */
    private function checkProvisionerAccountByElevating(string $username): void
    {
        if (!ProvisionerConnection::canElevate()) {
            $this->skip("Of het MySQL-account {$username} bestaat is hiervandaan niet te zien."
                . " Draai 'sudo -u {$username} php artisan tenancy:doctor' om het te controleren.");

            return;
        }

        $socket = (string) config('database.connections.provisioner.unix_socket');
        $database = (string) config('database.connections.provisioner.database');

        if ($socket === '') {
            $this->bad("DB_PROVISIONER_SOCKET staat leeg, dus {$username} zou over TCP verbinden,"
                . ' en dit account komt alleen via de socket binnen. Zet in .env:' . "\n"
                . '         DB_PROVISIONER_SOCKET=' . $this->serverSocket());

            return;
        }

        $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $database;

        $status = ProvisionerConnection::phpAsProvisioner(
            'try { new PDO(' . var_export($dsn, true) . ', ' . var_export($username, true) . ', "");'
            . ' exit(0); } catch (Throwable $e) { exit(1); }'
        );

        $status === 0
            ? $this->pass("MySQL-account {$username} bestaat en komt via de socket binnen")
            : $this->bad("MySQL-account {$username} komt niet binnen via {$socket}. Bestaat het account,"
                . ' en hangt het aan de Linux-gebruiker met dezelfde naam?'
                . ' Herstellen: sudo scripts/tenancy/setup-mysql.sh');
    }

    /**
     * Het pad naar de socket verschilt per distributie, dus dat aan de server
     * zelf vragen scheelt de lezer het opzoeken -- en een verkeerd overgetypt
     * pad geeft dezelfde melding als helemaal geen pad.
     */
    private function serverSocket(): string
    {
        try {
            return (string) (DB::connection('central')->selectOne('SELECT @@socket AS pad')->pad
                ?: '/var/run/mysqld/mysqld.sock');
        } catch (\Throwable $e) {
            return '/var/run/mysqld/mysqld.sock';
        }
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
                . ' MySQL-account eraan, dan kan het wachtwoord uit de .env weg. Dat doet:'
                . ' sudo scripts/tenancy/setup-mysql.sh --write-env');
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
