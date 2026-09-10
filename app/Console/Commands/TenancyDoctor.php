<?php

namespace App\Console\Commands;

use App\Http\Middleware\TenancyForStatefulApi;
use App\Models\Central\IssuerSetting;
use App\Models\Central\TenantProvisioningRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProvisionerConnection;
use App\Support\WorkerHeartbeat;
use App\Support\WorkerProcesses;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Checks what git does not hold. Reads only; never repairs.
 * A doctor that intervenes is one whose findings nobody reads any more.
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
         * With the central database down there is nothing to say about the
         * customers and the permissions -- but there is about the environment:
         * those checks need no database. Running them anyway saves a second
         * round, and sometimes the answer is right there: a missing APP_KEY, or
         * php not being allowed to start programs.
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
     * @return bool whether the central database can be reached; if not, no
     *              check after this one means anything
     */
    private function checkCentral(): bool
    {
        try {
            $name = DB::connection('central')->getDatabaseName();

            /**
             * getDatabaseName() only reads the settings and opens no
             * connection. Without a real question to the server the doctor
             * reported "fine" here while nothing was running, and the next
             * check fell over with a stack trace instead of a finding.
             */
            DB::connection('central')->select('SELECT 1');

            $this->pass("centrale verbinding: {$name}");
        } catch (\Throwable $e) {
            $this->bad('centrale verbinding: ' . $e->getMessage());

            return false;
        }

        /**
         * The central connection is fixed on TCP in the settings, but
         * migrations, creating customers and the template connection all run
         * over the default connection. That one can be broken while the central
         * one works fine, and then the doctor reported nothing while migrate
         * refused.
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
         * Work that is piling up. The heartbeat below says whether a worker is
         * alive; this says whether it also gets anywhere -- a worker that
         * breaks on every job has a heartbeat all the same.
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
     * Work that has been given up on.
     *
     * A failed job says nothing to whoever set it going: the invoice is not
     * sent, the calendar not updated, and no screen says so. They pile up
     * quietly in failed_jobs, and nobody looks there of their own accord.
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

        /**
         * The count alone says nothing. A thousand times the same error is one
         * thing that is broken; a thousand different ones is something else. So
         * it names the job that falls over most often and what on.
         *
         * Counted over the last two hundred rather than in SQL: grouping on two
         * text columns of unbounded length differs slightly per database, and
         * this only has to say where to look.
         */
        $recent = DB::connection('central')->table($table)
            ->orderByDesc('failed_at')
            ->limit(200)
            ->get(['payload', 'exception']);

        $worst = $recent
            ->map(fn ($row) => [
                'job' => json_decode($row->payload, true)['displayName'] ?? 'onbekende taak',
                'reason' => trim(strtok((string) $row->exception, "\n")),
            ])
            ->groupBy(fn (array $row) => $row['job'] . ' | ' . $row['reason'])
            ->sortByDesc(fn ($group) => $group->count())
            ->first();

        $summary = $worst
            ? sprintf(
                "\n         Meest voorkomend (%dx van de laatste %d): %s\n         %s",
                $worst->count(),
                $recent->count(),
                $worst->first()['job'],
                $worst->first()['reason'],
            )
            : '';

        $this->bad("{$total} mislukte ta(a)k(en), laatste op {$newest}." . $summary
            . "\n         Die zijn stil blijven liggen: geen factuur verstuurd, geen synchronisatie"
            . " gedraaid.\n         Bekijken: php artisan queue:failed"
            . "\n         Opnieuw:  php artisan queue:retry all");
    }

    /**
     * Are the workers running? An empty queue looks exactly like a worker that
     * is not there, so counting what is waiting says nothing. Every worker
     * therefore writes a heartbeat every minute, also when it has nothing to
     * do.
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
                $this->bad("Wachtrij '{$queue}': geen enkele hartslag. Draait '{$command}'?"
                    . $this->whoIsReporting($queue));

                continue;
            }

            $age = now()->timestamp - $beat;

            if ($age > WorkerHeartbeat::STALE_AFTER_MINUTES * 60) {
                $this->bad("Wachtrij '{$queue}': laatste hartslag "
                    . CarbonImmutable::createFromTimestamp($beat)->diffForHumans()
                    . ". De worker is gestopt. Start '{$command}'."
                    . $this->whoIsReporting($queue));

                continue;
            }

            /**
             * A worker reads .env at boot and holds on to it. Change something
             * after that and it keeps running on the old settings while the
             * heartbeat keeps coming in -- only the work breaks, with an error
             * pointing at settings that are correct by now.
             */
            $settings = WorkerHeartbeat::settingsFor($queue);
            $now = WorkerHeartbeat::codeVersion();

            /**
             * Ask the processes that are still there first. The single key each
             * worker overwrites keeps a fingerprint alive long after the worker
             * that wrote it is gone, and then this reads as a finding that no
             * restart can clear.
             */
            $live = WorkerHeartbeat::liveCodes($queue);
            $reported = $live === [] ? array_filter([WorkerHeartbeat::codeFor($queue)]) : $live;

            $outdated = array_filter($reported,
                fn (string $code) => $code !== '' && $now !== '' && $code !== $now);

            $stale = match (true) {
                $settings !== null && $settings !== WorkerHeartbeat::settingsFingerprint() => 'instellingen',
                $outdated !== [] => 'code',
                default => null,
            };

            $stale === null
                ? $this->pass("worker voor '{$queue}' draait")
                : $this->bad("Wachtrij '{$queue}': de worker draait op oudere {$stale} dan wat er nu"
                    . ' staat. Php houdt bij het opstarten alles vast, dus tot een herstart werkt hij'
                    . ' met wat er toen was:'
                    . "\n         sudo systemctl restart lavoro-worker lavoro-provisioning"
                    . $this->whoIsReporting($queue));
        }
    }

    /**
     * Which process writes those heartbeats.
     *
     * Without this the finding only says that old code is running, and it
     * stays up however often you restart -- because a restart only touches the
     * unit, not whatever else runs along on the same queue.
     */
    private function whoIsReporting(string $queue): string
    {
        $lines = WorkerHeartbeat::reporterLines($queue);
        $running = array_map(WorkerProcesses::describe(...), WorkerProcesses::forQueue($queue));
        $code = WorkerHeartbeat::codeVersion();

        $evidence = "\n         hier staat: " . base_path() . ', code '
            . ($code === '' ? 'onbekend' : substr($code, 0, 8));

        if ($lines !== []) {
            $evidence .= "\n         meldt zich: " . implode("\n                     ", $lines);
        }

        if ($running !== []) {
            $evidence .= "\n         draait nu:  " . implode("\n                     ", $running);
        }

        return $evidence . (count($lines) > 1 || count($running) > 1
            ? "\n         Meer dan één proces op dezelfde wachtrij: alleen de unit herstarten laat"
                . ' de rest gewoon doorlopen.'
            : '');
    }

    private function checkTenant(Tenant $tenant): void
    {
        $database = $tenant->getInternal('db_name');

        try {
            $tenant->tenancy_db_password
                ? $this->pass('wachtwoord is te ontsleutelen')
                : $this->bad('geen MySQL-login -- half aangemaakte tenant');
        } catch (\Throwable $e) {
            $this->bad('wachtwoord niet te ontsleutelen -- is APP_KEY gewisseld?');

            return;
        }

        /**
         * Connect rather than ask information_schema.
         *
         * That question went over the central connection, and that account may
         * deliberately only reach the central database. MySQL shows a database
         * only to whoever holds rights on it, so from there every customer
         * database looked as if it did not exist -- and then the doctor skipped
         * the rest of the checks for that customer, exactly the customer whose
         * state you wanted to know.
         */
        try {
            tenancy()->initialize($tenant);
            DB::connection('tenant')->getPdo();

            $this->pass("database {$database} is te openen met de eigen login");
        } catch (\Throwable $e) {
            tenancy()->end();

            $this->bad($this->tenantConnectionComplaint($database, $tenant, $e));
            $this->skip('overige controles voor deze tenant');

            return;
        }

        try {

            $missing = collect(['is_plannable_state', 'is_planned_state', 'is_closed_state',
                'is_planning_cancelled_state', 'is_invoiced_state', 'is_incomplete_state'])
                ->reject(fn ($flag) => DB::table('service_order_stages')->where($flag, true)->exists());

            $missing->isEmpty()
                ? $this->pass('een fase voor elke vlag')
                : $this->bad('geen fase voor: ' . $missing->implode(', '));

            /**
             * The roles come from the seeding, and that can fail silently --
             * the library does not look at the exit code. A customer without
             * roles looks healthy from the outside: you can log in, and only
             * when someone has to be added does it turn out there is nothing to
             * grant.
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
             * Write access is checked for the account the web server runs as,
             * because that one puts the uploads down. is_writable() looks at
             * the account running this command, and that is another one -- then
             * this says "fine" while every upload fails.
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
     * Why the customer database would not open.
     *
     * Three very different things look the same from the outside, and each asks
     * for something else. MySQL is no help there: a database that does not
     * exist and an account without rights both give 'access denied', because
     * you are not supposed to be able to probe what exists.
     *
     * So existence is asked separately on the provisioning connection, which
     * may look at the customer databases. If that fails too, it says so --
     * better no answer than a wrong answer.
     */
    private function tenantConnectionComplaint(string $database, Tenant $tenant, \Throwable $e): string
    {
        $login = $tenant->tenancy_db_username;
        $exists = $this->tenantDatabaseExists($database);

        if ($exists === false) {
            return "database {$database} bestaat niet, terwijl de klant wel in de registratie staat."
                . ' Opruimen of opnieuw aanmaken.';
        }

        if ($exists === true) {
            return "database {$database} bestaat, maar de login van deze klant ({$login}) komt er niet"
                . ' in. Het MySQL-account is weg of heeft zijn rechten verloren; opnieuw toekennen met'
                . " lavoro_admin.grant_tenant_access('{$database}', '{$login}').";
        }

        return "database {$database} gaat niet open met de login van deze klant ({$login}), en of hij"
            . ' bestaat is hiervandaan niet na te gaan: ' . $e->getMessage();
    }

    /**
     * Does the customer database exist? Asked on the provisioning connection,
     * because the central account may deliberately only reach the central
     * database and therefore does not see the customer databases at all.
     *
     * @return bool|null null when it cannot be established
     */
    private function tenantDatabaseExists(string $database): ?bool
    {
        try {
            return (bool) DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'))
                ->selectOne(
                    'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?',
                    [$database]
                );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The preconditions that live in the environment rather than in the code:
     * which PHP extensions are there, how .env stands, and whether mail can go
     * out. Every one of them a thing you notice only when you need it.
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
         * With exec or shell_exec in disable_functions nothing can be elevated
         * any more -- and worse: the checks above that lean on it get "no" back
         * without anything being wrong. Then the doctor reports a problem that
         * does not exist and hides the real one.
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
        $this->checkApiAuthentication();
        $this->checkCachedConfigIsCurrent();
        $this->checkBuiltAssets();
    }

    /**
     * What the build produces is not in git: public/build and the service
     * worker only come into being on npm run build. Skip that step or let it
     * fail, and the server runs new code with old or missing files -- and that
     * raises no error on the server itself at all.
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
     * @return string|null the short hash of HEAD, or null when there is no git
     *                     checkout here or git may not be started
     */
    private function gitRevision(): ?string
    {
        $revision = trim((string) shell_exec(
            'git -C ' . escapeshellarg(base_path()) . ' rev-parse --short HEAD 2>/dev/null'
        ));

        return $revision === '' ? null : $revision;
    }

    /**
     * The versions this is built on. An older PHP or database does not refuse
     * politely but produces a strange error in a random place.
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
     * The storage the design assumes.
     *
     * With the queue on sync, provisioning runs inside the web request as the
     * application's account -- which may not create databases -- instead of in
     * the worker that may. With the session not central, logging in looks for
     * its user in the wrong database.
     */
    /**
     * Does the app run on the settings that are in .env?
     *
     * With a cached configuration the app no longer reads .env but
     * bootstrap/cache/config.php. Change .env after that and forget to cache
     * again, and it keeps running on the old values -- with nothing showing it.
     * A wrong APP_URL from before the caching means /api gets no session, while
     * .env looks right and so does this command, which reads the fresh
     * configuration.
     */
    private function checkCachedConfigIsCurrent(): void
    {
        $cache = base_path('bootstrap/cache/config.php');

        if (!file_exists($cache)) {
            app()->environment('production')
                ? $this->bad('De configuratie is niet gecachet. Op een server hoort'
                    . ' php artisan config:cache te draaien; zonder dat leest elk verzoek .env opnieuw.')
                : $this->pass('configuratie niet gecachet (leest .env rechtstreeks)');

            return;
        }

        $env = base_path('.env');

        if (file_exists($env) && filemtime($env) > filemtime($cache)) {
            $this->bad('.env is aangepast na de laatste config:cache ('
                . date('d-m-Y H:i', filemtime($cache)) . '), dus de app draait nog op de oude'
                . " waarden.\n         Bijwerken met: php artisan config:cache");

            return;
        }

        $this->pass('gecachete configuratie is van na de laatste wijziging in .env');
    }

    /**
     * Whether the planner and the other screens that talk to /api stay logged
     * in.
     *
     * Those requests do not run through the web group but through Sanctum's own
     * pipeline, and it skips that pipeline as soon as it does not recognise the
     * request as coming from its own front end. Then there is no session, no
     * customer and no user, and the planner gets 'Unauthenticated' back on
     * every action -- while the ordinary screens work fine. The app itself does
     * not show it; it hangs on three settings.
     */
    private function checkApiAuthentication(): void
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $served = $host . ($port ? ':' . $port : '');
        $stateful = collect(config('sanctum.stateful', []))->map(fn ($domain) => trim($domain));

        if (blank($host)) {
            $this->bad('APP_URL is leeg of onleesbaar. Sanctum leidt daaruit af welke voorkant'
                . ' bij deze installatie hoort; zonder die waarde blijft elk verzoek naar /api'
                . ' onaangemeld en werkt de planner niet.');

            return;
        }

        /**
         * Not checkable from the command line: whether this is also the address
         * customers have in their browser. If it is off -- www in front, http
         * instead of https, an old domain -- Sanctum does not recognise the
         * request as its own front end and there is no session. So it is
         * spelled out here, to be checked by eye.
         */
        $this->line("       APP_URL is {$url}; verzoeken van /api moeten van precies dat adres komen.");

        if (app()->environment('production') && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->bad("APP_URL wijst naar {$host}, en dat is op een server nooit het adres waarop"
                . ' klanten binnenkomen. Verzoeken van het echte domein gelden dan niet als eigen'
                . ' voorkant, en alles wat over /api gaat -- de planner voorop -- krijgt'
                . " 'Unauthenticated' terug terwijl de gewone schermen het wel doen.");
        } elseif ($stateful->contains($served)) {
            $this->pass("{$served} telt als eigen voorkant");
        } else {
            $this->bad("De app draait volgens APP_URL op {$served}, maar dat staat niet in"
                . ' SANCTUM_STATEFUL_DOMAINS (' . $stateful->implode(', ') . ").\n"
                . "         Alles wat over /api gaat krijgt dan 'Unauthenticated' terug. Zet dat"
                . ' adres erbij en draai daarna php artisan config:cache.');
        }

        /**
         * The tenant is set in the only hook Sanctum offers in that pipeline.
         * Should Sanctum's configuration ever be published again, the default
         * class is back in it and the customer is gone without anything
         * breaking -- except every /api request.
         */
        config('sanctum.middleware.authenticate_session') === TenancyForStatefulApi::class
            ? $this->pass('api-verzoeken krijgen hun klant mee')
            : $this->bad('sanctum.middleware.authenticate_session hoort '
                . TenancyForStatefulApi::class . ' te zijn, maar is '
                . var_export(config('sanctum.middleware.authenticate_session'), true)
                . '. Zonder dat haakje zoekt /api de gebruiker in de verkeerde database.');

        collect(app(Router::class)->getMiddlewareGroups()['api'] ?? [])
            ->contains(EnsureFrontendRequestsAreStateful::class)
            ? $this->pass('api-verzoeken mogen de sessie gebruiken')
            : $this->bad('De api-groep mist ' . class_basename(EnsureFrontendRequestsAreStateful::class)
                . ' (statefulApi() in bootstrap/app.php). Zonder die middleware is er op /api geen'
                . ' sessie en dus geen ingelogde gebruiker.');
    }

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
     * dompdf puts a font's metrics in a directory of its own and does not
     * create that directory. Without it no invoice comes out but an error --
     * and you only notice when you want to send one.
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
     * A full disk breaks everything in a way that points at a full disk
     * nowhere: uploads that arrive halfway, a database that stops writing,
     * sessions that vanish. You want to know before it gets that far.
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
     * The log file grows without end when LOG_CHANNEL is on 'single'. That is
     * noticed once the disk is full, and by then the cause cannot be seen any
     * more -- the log itself is too big to open.
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
     * Without these details no invoice is right.
     *
     * Deliberately not among the environment checks: this reads the central
     * database, and those checks should work precisely when there is no
     * database. Otherwise the doctor falls over at the moment you need it most.
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
     * The rights of the database accounts are checked by
     * scripts/tenancy/verify-mysql.sh.
     *
     * It tries, as the provisioner, to create a database inside and outside the
     * allowed names, and as the application one that should be refused -- more
     * thoroughly than is possible from here, because that needs root. Only the
     * reference here; writing the same check twice produces two answers that
     * drift apart. deploy.sh runs it along.
     */
    private const PRIVILEGES_STALE_AFTER_DAYS = 30;

    /**
     * The rights of the database accounts are something this command cannot
     * check itself: that needs to look into mysql.user and only root may.
     * verify-mysql.sh can, and leaves its verdict behind; it is read here.
     *
     * A run without root skips half of it. That does not count as approval,
     * otherwise "ran it quickly without sudo" would end up as a green tick.
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
         * Checks that did not apply yet -- there is no customer at all, so no
         * customer account to check either -- are not a hole in the check. Worth
         * mentioning though, because as soon as there is a customer a new run
         * says more than this one.
         */
        $pending = (int) ($outcome['not_applicable'] ?? 0);

        $this->pass("rechten van de databaseaccounts nagekeken ({$moment})"
            . ($pending > 0 ? ", op {$pending} punt(en) na die toen nog niet bestonden" : ''));
    }

    /**
     * The admin panel puts down requests only the provisioner worker can carry
     * out. If it is not running, a request quietly sits there and the panel
     * looks broken. This is the place where that shows.
     *
     * Three separate questions, each in a method of its own. They used to sit
     * in one block with a 'return' in between: with no failed requests the
     * doctor skipped everything after it -- the account, the Linux user, the
     * elevating, the write access. So it grew quieter the less was wrong, and
     * reported "all fine" about checks that had not run.
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
         * No requests does not mean the worker runs -- that can only be seen
         * from work that got finished. Without that this path is unproven and
         * not fine.
         */
        TenantProvisioningRequest::on('central')->where('status', 'done')->exists()
            ? $this->pass('Geen aanvragen in de wacht; de worker heeft eerder werk afgerond.')
            : $this->skip('Geen aanvragen in de wacht, en er is er nog nooit een afgerond -- of de'
                . ' worker draait is hiermee niet vast te stellen.');
    }

    /**
     * The reason is in the request itself. Showing it here saves the detour via
     * the admin panel, and whoever works this out from the command line does
     * not have that panel open.
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
     * The account allowed to create and drop customer databases.
     *
     * Two separate questions, because they can go wrong independently: does the
     * MySQL account exist, and does it hang on a Linux user rather than on a
     * password in a file.
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
             * Not being able to log in proves nothing. An account that hangs on
             * a Linux user should refuse here, and an account that does not
             * exist at all does exactly the same. So do not approve this.
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
     * If the account is meant to hang on a Linux user (no password), that user
     * has to exist as well. Without them nobody can log in any more and
     * creating customers comes to a halt.
     */
    /**
     * Can the tenant commands elevate themselves?
     *
     * Without the sudo rule everything still works, but then
     * "php artisan tenant:create" returns no customer but an explanation of
     * which command you should have typed. Better read here than there.
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
     * The provisioning worker creates the folders of a new customer. If it may
     * not write in storage/, the customer is created but its first upload fails
     * -- an empty folder nobody misses until that happens.
     *
     * Only testable when we can become that user; otherwise our own access says
     * nothing about theirs.
     */
    /**
     * May the web server write in storage/?
     *
     * The worker and the commands run as the installation's account, but php
     * under the web server often runs as something else -- 'nobody' on
     * LiteSpeed, 'www-data' on Apache. If that account cannot write in
     * storage/logs, every error from a web request disappears without a sound:
     * no page, no line, nothing to look up.
     *
     * Who that account is can be read from the compiled templates: the web
     * server writes those itself, on the first page it renders.
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
     * The account php runs as under the web server, read from the compiled
     * templates: the web server writes those itself. Null when there are none
     * yet.
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
     * Whether another account may write somewhere. Not doable with is_writable:
     * that looks at the account running this command.
     *
     * An ACL can grant write access where the permission bits know nothing of
     * it, so that is laid next to it before anything is reported.
     */
    private function userCanWrite(string $account, string $path): bool
    {
        /** root does not care about permission bits. */
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
         * Three outcomes, because two very different causes look the same.
         * Rights on storage/ itself do not help when the user may not enter the
         * folders above it: with the installation in a home directory, that one
         * is 0750 by default and they do not even reach the door. Without that
         * distinction you send someone repeating setfacl on storage/ until they
         * give up.
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
     * setfacl sits in a separate package that is far from everywhere. Without
     * this the advice above produces 'command not found', and then the question
     * is what you did wrong instead of what you have to install.
     */
    private function setfaclHint(): string
    {
        $found = trim((string) shell_exec('command -v setfacl 2>/dev/null'));

        return $found === '' ? "\n" . '         (setfacl zit in het pakket acl: apt install acl)' : '';
    }

    /**
     * The directories between / and the path the provisioner cannot get
     * through. Only the ones they have no passage through now, so that no more
     * is opened up than needed.
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
     * From this account the provisioner account always refuses, because it
     * hangs on a Linux user. So that says nothing about whether it exists.
     *
     * If we may become that user, it can be seen: from there we do exactly what
     * provisioning will do later -- in through the socket, without a password.
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

    /** Php opening a connection as the provisioner; leaves $pdo behind. */
    private function provisionerPdo(string $username): string
    {
        $dsn = 'mysql:unix_socket=' . config('database.connections.provisioner.unix_socket')
            . ';dbname=' . config('database.connections.provisioner.database');

        return '$pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($username, true) . ', "");';
    }

    /**
     * The procedure that grants a customer login its rights.
     *
     * Without that procedure creating a customer succeeds up to and including
     * the database and strands after it -- while everything above is fine. So
     * it is called with the landlord database: that should be refused, and how
     * it refuses shows whether it is there and whether its own check still
     * holds. Nothing changes: a refusal is the goal.
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
         * If we already run as the provisioner the probe can go directly.
         * Otherwise through sudo. Without that distinction this check was
         * skipped precisely on the machine where it is easiest to do.
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
     * The path to the socket differs per distribution, so asking the server
     * itself saves the reader the lookup -- and a mistyped path gives the same
     * message as no path at all.
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
         * A customer's folders are left behind when the cleanup got stuck
         * halfway. They do no harm, but they can hold the files of a company
         * that is long gone -- and that should not quietly stay on the disk. An
         * empty folder is not a finding: there is nothing to decide about it,
         * and a deploy that goes red over one only teaches you to ignore the
         * findings.
         */
        $folders = collect(File::directories(storage_path()))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => str_starts_with($name, 'tenant-'))
            ->reject(fn (string $name) => $ids->contains(substr($name, strlen('tenant-'))))
            ->reject(fn (string $name) => empty(File::allFiles(storage_path($name))));

        if ($folders->isEmpty()) {
            $this->pass('geen mappen zonder tenant');

            return;
        }

        /**
         * Never suggest 'rm -rf'.
         *
         * Such a folder is called orphaned, but that only means no customer
         * with that id is in the registry any more -- not that there is nothing
         * in it. On production it held hundreds of photos and pdfs of real
         * service orders, and that advice was followed. What is in it belongs in
         * the finding, and the cleaning up goes through a command that first
         * shows what it will delete and asks for confirmation.
         */
        foreach ($folders as $folder) {
            $path = storage_path($folder);
            $files = collect(File::allFiles($path));
            $size = $files->sum(fn ($file) => $file->getSize());

            $this->bad(sprintf(
                "map zonder tenant: %s -- %d bestand(en), %s.\n"
                . '         Er staat geen klant met dit id meer in de registratie, maar de inhoud'
                . " kan van een klant zijn die opnieuw is aangemaakt.\n"
                . '         Kijk er eerst in (%s), en ruim hem daarna op met:'
                . "\n         php artisan tenancy:prune-storage %s",
                $folder,
                $files->count(),
                $this->humanSize($size),
                $path,
                $folder,
            ));
        }
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }

            $bytes /= 1024;
        }

        return $bytes . ' B';
    }
}
