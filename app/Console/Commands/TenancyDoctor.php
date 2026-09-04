<?php

namespace App\Console\Commands;

use App\Models\Central\IssuerSetting;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use App\Support\WorkerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

        /**
         * Ligt de centrale database eruit, dan valt er over de klanten en de
         * rechten niets te zeggen -- maar over de omgeving wel: die controles
         * hebben geen database nodig. Ze hier toch draaien scheelt een tweede
         * ronde, en soms staat het antwoord er meteen bij: een APP_KEY die
         * ontbreekt, of php dat geen programma's mag starten.
         */
        $central = $this->checkCentral();

        if ($central) {
            foreach (Tenant::on('central')->orderBy('name')->get() as $tenant) {
                $this->newLine();
                $this->line($tenant->name);
                $this->checkTenant($tenant);
            }
        }

        $this->newLine();
        $this->checkEnvironment();

        if ($central) {
            $this->checkIssuer();

            $this->newLine();
            $this->checkPrivileges();

            $this->newLine();
            $this->checkProvisioning();

            $this->newLine();
            $this->checkOrphans();
        }

        $this->newLine();

        if (!$central) {
            $this->error('De centrale database is onbereikbaar, dus alles wat daarvan afhangt is'
                . ' overgeslagen. Los dat eerst op en draai opnieuw.');

            return self::FAILURE;
        }

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

        $this->checkFailedJobs();
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
     * Werk dat is opgegeven.
     *
     * Een mislukte job zegt niets tegen wie hem in gang zette: de factuur wordt
     * niet verstuurd, de agenda niet bijgewerkt, en er komt geen scherm waar dat
     * op staat. Ze stapelen zich stil op in failed_jobs, en niemand kijkt daar
     * uit zichzelf.
     */
    private function checkFailedJobs(): void
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        if (!DB::connection('central')->getSchemaBuilder()->hasTable($table)) {
            $this->skip("tabel {$table} bestaat niet, dus mislukt werk is niet na te gaan");

            return;
        }

        $failed = DB::connection('central')->table($table);
        $total = $failed->count();

        if ($total === 0) {
            $this->pass('geen mislukte taken');

            return;
        }

        $newest = $failed->max('failed_at');

        $this->bad("{$total} mislukte ta(a)k(en), laatste op {$newest}. Die zijn stil blijven liggen:"
            . " geen factuur verstuurd, geen synchronisatie gedraaid.\n"
            . "         Bekijken: php artisan queue:failed\n"
            . '         Opnieuw:  php artisan queue:retry all');
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

            if ($age > WorkerHeartbeat::STALE_AFTER_MINUTES * 60) {
                $this->bad("Wachtrij '{$queue}': laatste hartslag "
                    . CarbonImmutable::createFromTimestamp($beat)->diffForHumans()
                    . ". De worker is gestopt. Start '{$command}'.");

                continue;
            }

            /**
             * Een worker leest .env bij het opstarten en houdt dat vast. Is er
             * daarna iets veranderd, dan draait hij door op de oude instellingen
             * terwijl de hartslag gewoon blijft komen -- en loopt alleen het
             * werk stuk, met een foutmelding die naar instellingen wijst die
             * inmiddels wel kloppen.
             */
            $settings = WorkerHeartbeat::settingsFor($queue);
            $code = WorkerHeartbeat::codeFor($queue);
            $now = WorkerHeartbeat::codeVersion();

            $stale = match (true) {
                $settings !== null && $settings !== WorkerHeartbeat::settingsFingerprint() => 'instellingen',
                $code !== null && $code !== '' && $now !== '' && $code !== $now => 'code',
                default => null,
            };

            $stale === null
                ? $this->pass("worker voor '{$queue}' draait")
                : $this->bad("Wachtrij '{$queue}': de worker draait op oudere {$stale} dan wat er nu"
                    . ' staat. Php houdt bij het opstarten alles vast, dus tot een herstart werkt hij'
                    . " met wat er toen was:\n"
                    . '         sudo systemctl restart lavoro-worker lavoro-provisioning');
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

            /**
             * De rollen komen uit het zaaien, en dat kan stil mislukken -- de
             * bibliotheek kijkt niet naar de exitcode. Een klant zonder rollen
             * ziet er verder gezond uit: je kunt inloggen, en pas als er iemand
             * bij moet blijkt dat er niets toe te kennen valt.
             */
            $expected = array_keys(include base_path('database/seeders/data/tenant_roles.php'));
            $absent = array_diff($expected, Role::pluck('name')->all());

            $absent === []
                ? $this->pass(count($expected) . ' rollen aanwezig')
                : $this->bad('rollen ontbreken: ' . implode(', ', $absent)
                    . '. Het zaaien is niet gelukt; herstellen met:' . "\n"
                    . "         php artisan tenants:seed --tenants={$tenant->id}");

            $users = User::withTrashed()->pluck('email');
            $known = DB::connection('central')->table('user_tenant_lookups')
                ->where('tenant_id', $tenant->id)->pluck('email');

            $orphan = $users->diff($known);

            $orphan->isEmpty()
                ? $this->pass("{$users->count()} gebruikers in de centrale lijst")
                : $this->bad($orphan->count() . ' gebruiker(s) zonder centrale rij -- die kunnen niet inloggen');

            /**
             * Schrijfrecht wordt nagekeken voor het account waaronder de
             * webserver draait, want die zet de uploads neer. is_writable()
             * kijkt naar het account dat dit commando draait, en dat is een
             * ander -- dan staat hier 'in orde' terwijl elke upload mislukt.
             */
            $account = $this->webAccount();

            foreach (['public', 'local'] as $disk) {
                $path = storage_path("tenant-{$tenant->id}/{$disk}");

                if (!File::isDirectory($path)) {
                    $this->bad("opslag {$disk} ontbreekt");

                    continue;
                }

                $writable = $account === null
                    ? is_writable($path)
                    : $this->userCanWrite($account, $path);

                $writable
                    ? $this->pass("opslag {$disk}")
                    : $this->bad("opslag {$disk} is niet beschrijfbaar voor "
                        . ($account ?? 'dit account') . ', dus uploads mislukken. Herstellen met:'
                        . "\n         sudo setfacl -R -m u:{$account}:rwX " . storage_path());
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
        $this->checkBuiltAssets();
    }

    /**
     * Wat de build maakt staat niet in git: public/build en de service worker
     * ontstaan pas bij npm run build. Wordt die stap overgeslagen of loopt hij
     * stuk, dan draait de server met nieuwe code en oude of ontbrekende
     * bestanden -- en dat geeft geen enkele foutmelding op de server zelf.
     */
    private function checkBuiltAssets(): void
    {
        file_exists(public_path('build/manifest.json'))
            ? $this->pass('gebouwde assets aanwezig')
            : $this->bad('public/build/manifest.json ontbreekt -- de build is hier nooit gedraaid.'
                . ' Elke pagina geeft dan een Vite-fout. Draai npm ci && npm run build.');

        $worker_path = public_path('service-worker.js');

        if (!file_exists($worker_path)) {
            $this->bad('public/service-worker.js ontbreekt -- git houdt dat bestand niet meer vast,'
                . ' de build maakt het. Zonder dat bestand cachet de browser niets meer.'
                . ' Draai npm run build.');

            return;
        }

        $revision = $this->gitRevision();

        if ($revision === null) {
            $this->pass('service worker aanwezig');

            return;
        }

        str_contains(File::get($worker_path), "lavoro-cache-{$revision}")
            ? $this->pass('service worker hoort bij de uitgerolde code')
            : $this->bad('public/service-worker.js komt van een oudere build dan de code die hier'
                . ' staat. Browsers blijven dan oude bestanden uit hun cache serveren.'
                . ' Draai npm run build.');
    }

    /**
     * @return string|null de korte hash van HEAD, of null als hier geen
     *                     git-checkout staat of git niet gestart mag worden
     */
    private function gitRevision(): ?string
    {
        $revision = trim((string) shell_exec(
            'git -C ' . escapeshellarg(base_path()) . ' rev-parse --short HEAD 2>/dev/null'
        ));

        return $revision === '' ? null : $revision;
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
        $this->checkDiskSpace();
        $this->checkLogRotation();

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

    /**
     * Een volle schijf breekt alles op een manier die nergens naar een volle
     * schijf wijst: uploads die half aankomen, een database die niet meer
     * schrijft, sessies die verdwijnen. Dat wil je weten voordat het zover is.
     */
    private function checkDiskSpace(): void
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || $total <= 0) {
            $this->skip('vrije schijfruimte niet op te vragen');

            return;
        }

        $gigabytes = round($free / (1024 ** 3), 1);
        $percentage = round($free / $total * 100);

        match (true) {
            $percentage < 5 => $this->bad("nog {$gigabytes} GB vrij ({$percentage}%). Bij een volle"
                . ' schijf mislukken uploads en schrijft de database niet meer.'),
            $percentage < 15 => $this->skip("nog {$gigabytes} GB vrij ({$percentage}%) -- houd het in de gaten"),
            default => $this->pass("schijfruimte: {$gigabytes} GB vrij ({$percentage}%)"),
        };
    }

    /**
     * Het logbestand groeit zonder ophouden als LOG_CHANNEL op 'single' staat.
     * Dat valt pas op als de schijf vol is, en dan is de oorzaak niet meer te
     * zien -- het logboek zelf is dan te groot om te openen.
     */
    private function checkLogRotation(): void
    {
        $log = storage_path('logs/laravel.log');
        $size = is_file($log) ? (int) @filesize($log) : 0;
        $megabytes = (int) round($size / (1024 ** 2));

        if ($megabytes < 100) {
            $this->pass('logboek heeft een werkbare omvang' . ($megabytes > 0 ? " ({$megabytes} MB)" : ''));

            return;
        }

        $daily = str_contains((string) config('logging.default'), 'daily')
            || in_array('daily', (array) config('logging.channels.stack.channels', []), true);

        $daily
            ? $this->skip("Het logboek is {$megabytes} MB. Er wordt wel geroteerd; de oude bestanden"
                . ' mogen weg.')
            : $this->bad("Het logboek is {$megabytes} MB en groeit door: LOG_CHANNEL rouleert niet."
                . " Zet LOG_STACK=daily in .env en herstart php.\n"
                . "         Nu opruimen: truncate -s 0 {$log}");
    }

    /**
     * Zonder deze gegevens klopt er geen enkele factuur.
     *
     * Staat bewust niet bij de omgevingscontroles: dit leest de centrale
     * database, en die controles horen het juist te doen als er geen database
     * is. Anders klapt de doctor eruit op het moment dat je hem het hardst
     * nodig hebt.
     */
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
    /**
     * Drie losse vragen, elk in een eigen methode. Ze stonden achter elkaar in
     * één blok en daar zat een 'return' tussen: waren er geen mislukte
     * aanvragen, dan sloeg de doctor alles daarna over -- het account, de
     * Linux-gebruiker, het verheffen, de schrijfrechten. Hij werd dus stiller
     * naarmate er minder mis was, en meldde 'alles in orde' over controles die
     * niet gedraaid hadden.
     */
    private function checkProvisioning(): void
    {
        $this->line('Provisioning');

        $this->checkPendingRequests();
        $this->checkFailedRequests();
        $this->checkProvisionerAccount();
    }

    private function checkPendingRequests(): void
    {
        $requests = TenantProvisioningRequest::on('central')
            ->whereIn('status', ['queued', 'running'])
            ->get();

        $stuck = $requests->filter(fn ($request) => $request->created_at?->lt(now()->subMinutes(15)));

        if ($stuck->isNotEmpty()) {
            $this->bad($stuck->count() . ' aanvraag(en) staan langer dan een kwartier stil. Draait'
                . ' "php artisan queue:work --queue=provisioning" als lavoro_provisioner?');

            return;
        }

        if ($requests->isNotEmpty()) {
            $this->pass($requests->count() . ' aanvraag(en) onderweg.');

            return;
        }

        /**
         * Geen aanvragen betekent niet dat de worker draait -- dat is alleen te
         * zien aan werk dat af is gekomen. Zonder dat is dit pad onbewezen en
         * niet in orde.
         */
        TenantProvisioningRequest::on('central')->where('status', 'done')->exists()
            ? $this->pass('Geen aanvragen in de wacht; de worker heeft eerder werk afgerond.')
            : $this->skip('Geen aanvragen in de wacht, en er is er nog nooit een afgerond -- of de'
                . ' worker draait is hiermee niet vast te stellen.');
    }

    /**
     * De reden staat in de aanvraag zelf. Die hier tonen scheelt de omweg langs
     * het beheerpaneel, en juist wie dit vanaf de opdrachtregel uitzoekt heeft
     * dat paneel niet open.
     */
    private function checkFailedRequests(): void
    {
        $failed = TenantProvisioningRequest::on('central')
            ->where('status', 'failed')->orderByDesc('id')->get();

        if ($failed->isEmpty()) {
            $this->pass('Geen mislukte aanvragen.');

            return;
        }

        $this->bad($failed->count() . ' mislukte aanvraag(en):');

        foreach ($failed as $request) {
            $this->line("         {$request->action} '{$request->name}': "
                . Str::limit((string) $request->error, 300));
        }

        $this->line('       Opgelost? Haal ze weg in het beheerpaneel en probeer het opnieuw.');
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

        $this->checkGrantProcedure($username);
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
        $account = $this->webAccount();

        if ($account === null) {
            $this->skip('Als wie de webserver draait is nog niet te zien: er zijn geen gecompileerde'
                . ' sjablonen. Open een pagina en draai dit opnieuw.');

            return;
        }

        $log = storage_path('logs/laravel.log');

        if (!file_exists($log)) {
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

    /**
     * Het account waaronder php onder de webserver draait, afgelezen aan de
     * gecompileerde sjablonen: die schrijft de webserver zelf. Null als er nog
     * geen zijn.
     */
    private function webAccount(): ?string
    {
        static $account = false;

        if ($account !== false) {
            return $account;
        }

        $compiled = glob(storage_path('framework/views/*.php')) ?: [];

        return $account = $compiled === [] ? null : $this->ownerOf($compiled[0]);
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

        $status = ProvisionerConnection::phpAsProvisioner(
            'try { ' . $this->provisionerPdo($username) . ' exit(0); } catch (Throwable $e) { exit(1); }'
        );

        $status === 0
            ? $this->pass("MySQL-account {$username} bestaat en komt via de socket binnen")
            : $this->bad("MySQL-account {$username} komt niet binnen via {$socket}. Bestaat het account,"
                . ' en hangt het aan de Linux-gebruiker met dezelfde naam?'
                . ' Herstellen: sudo scripts/tenancy/setup-mysql.sh');
    }

    /** Php dat als de provisioner een verbinding opzet; laat $pdo achter. */
    private function provisionerPdo(string $username): string
    {
        $dsn = 'mysql:unix_socket=' . config('database.connections.provisioner.unix_socket')
            . ';dbname=' . config('database.connections.provisioner.database');

        return '$pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($username, true) . ', "");';
    }

    /**
     * De procedure die een klantlogin zijn rechten geeft.
     *
     * Zonder die procedure lukt het aanmaken van een klant tot en met de
     * database en strandt het daarna -- terwijl alles hierboven in orde is. Ze
     * wordt daarom aangeroepen met de landlord-database: dat hoort geweigerd te
     * worden, en aan hoe hij weigert is te zien of hij er is en of zijn
     * controle nog klopt. Er verandert niets: een weigering is het doel.
     */
    private function checkGrantProcedure(string $username): void
    {
        $procedure = (string) config('tenancy.database.grant_procedure', 'lavoro_admin.grant_tenant_access');
        [$schema, $name] = array_pad(explode('.', $procedure, 2), 2, '');

        if ($schema === '' || $name === '') {
            $this->bad("tenancy.database.grant_procedure hoort 'database.procedure' te zijn,"
                . " niet '{$procedure}'.");

            return;
        }

        $forbidden = (string) config('database.connections.central.database');
        $call = 'CALL `' . $schema . '`.`' . $name . '`(' . var_export($forbidden, true) . ", 'doctor_probe')";

        /**
         * Draaien we zelf al als de provisioner, dan kan de proef rechtstreeks.
         * Anders via sudo. Zonder dat onderscheid werd deze controle juist
         * overgeslagen op de machine waar hij het makkelijkst te doen is.
         */
        if (ProvisionerConnection::linuxUser() === $username) {
            try {
                DB::connection('provisioner')->statement($call);
                $status = 2;
            } catch (\Throwable $e) {
                $status = (string) $e->getCode() === '45000' ? 0 : 3;
            }
        } elseif (ProvisionerConnection::canElevate()) {
            $status = ProvisionerConnection::phpAsProvisioner(
                'try { ' . $this->provisionerPdo($username)
                . ' $pdo->exec(' . var_export($call, true) . ');'
                . ' exit(2); } catch (Throwable $e) { exit((string) $e->getCode() === "45000" ? 0 : 3); }'
            );
        } else {
            $this->skip("Of {$procedure} bestaat en nog steeds weigert wat hij hoort te weigeren is"
                . ' hiervandaan niet te zien.');

            return;
        }

        match ($status) {
            0 => $this->pass("{$procedure} bestaat en weigert alles buiten de klantnaamruimte"),
            2 => $this->bad("{$procedure} deelde rechten uit op {$forbidden}. Hij hoort alles buiten"
                . ' de klantnaamruimte te weigeren; zo kan de provisioner overal rechten op geven.'
                . ' Herstellen: sudo scripts/tenancy/setup-mysql.sh'),
            default => $this->bad("{$procedure} is niet aan te roepen. Zonder die procedure komt een"
                . ' nieuwe klant tot en met de database en strandt het daarna.'
                . ' Herstellen: sudo scripts/tenancy/setup-mysql.sh'),
        };
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

        /**
         * De mappen van een klant blijven achter als het opruimen halverwege is
         * blijven steken. Ze doen geen kwaad, maar er kunnen bestanden van een
         * bedrijf in staan dat allang weg is -- en dat hoort niet stilletjes op
         * de schijf te blijven liggen.
         */
        $folders = collect(File::directories(storage_path()))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => str_starts_with($name, 'tenant-'))
            ->reject(fn (string $name) => $ids->contains(substr($name, strlen('tenant-'))));

        $folders->isEmpty() ? $this->pass('geen mappen zonder tenant')
            : $this->bad('map zonder tenant: ' . $folders->implode(', ')
                . '. Weg te halen met: rm -rf ' . storage_path($folders->first()));
    }
}
