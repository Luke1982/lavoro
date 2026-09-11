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

    protected $description = 'Checks the tenancy setup and every tenant separately';

    private int $failed = 0;

    private int $passed = 0;

    public function handle(): int
    {
        $this->line('Central');

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
            $this->error('The central database cannot be reached, so everything depending on it has'
                . ' been skipped. Solve that first and run again.');

            return self::FAILURE;
        }

        if ($this->failed === 0) {
            $this->info("All fine ({$this->passed} checks).");

            return self::SUCCESS;
        }

        $this->error("{$this->failed} problem(s), {$this->passed} fine.");

        return self::FAILURE;
    }

    private function pass(string $m): void
    {
        $this->line("  <fg=green>OK</>   {$m}");
        $this->passed++;
    }

    private function bad(string $m): void
    {
        $this->line("  <fg=red>FAIL</> {$m}");
        $this->failed++;
    }

    private function skip(string $m): void
    {
        $this->line("  <fg=yellow>SKIP</> {$m}");
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

            $this->pass("central connection: {$name}");
        } catch (\Throwable $e) {
            $this->bad('central connection: ' . $e->getMessage());

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
            $this->pass("connection '{$template}' (migrations and provisioning)");
        } catch (\Throwable $e) {
            $socket = (string) config("database.connections.{$template}.unix_socket");
            $host = (string) config("database.connections.{$template}.host");

            $socket === ''
                ? $this->bad('standaardverbinding: ' . $e->getMessage())
                : $this->bad("The default connection runs over the socket {$socket}. MySQL therefore"
                    . " sees the account as 'localhost' and not as {$host}, and there it does not"
                    . ' exist. Take DB_SOCKET out of .env.');
        }

        foreach (['tenants', 'user_tenant_lookups', 'sessions', 'cache', 'jobs', 'packages', 'modules'] as $table) {
            DB::connection('central')->getSchemaBuilder()->hasTable($table)
                ? $this->pass("table {$table}")
                : $this->bad("table {$table} is missing -- has migrate run?");
        }

        config('session.connection') === 'central'
            ? $this->pass('SESSION_CONNECTION=central')
            : $this->bad('SESSION_CONNECTION is not central');

        try {
            Cache::put('doctor', 1, 5);
            Cache::get('doctor') === 1 ? $this->pass('cache reads and writes') : $this->bad('cache does not write');
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
            ? $this->bad('the oldest waiting job is more than an hour old -- is the worker getting anywhere?')
            : $this->pass('no work piling up');

        $this->checkFailedJobs();
        $this->checkWorkers();

        $beat = Cache::get('scheduler_heartbeat');

        if (!$beat) {
            $this->skip('scheduler heartbeat never written (new installation, or cron is not running)');
        } elseif ($beat < now()->subMinutes(15)->timestamp) {
            $this->bad('scheduler heartbeat is older than 15 minutes -- cron is not running');
        } else {
            $this->pass('scheduler is running');
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
            $this->skip("table {$table} does not exist, so failed work cannot be checked");

            return;
        }

        $failed = DB::connection('central')->table($table);
        $total = $failed->count();

        if ($total === 0) {
            $this->pass('no failed jobs');

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
                "\n         Most frequent (%dx of the last %d): %s\n         %s",
                $worst->count(),
                $recent->count(),
                $worst->first()['job'],
                $worst->first()['reason'],
            )
            : '';

        $this->bad("{$total} mislukte ta(a)k(en), laatste op {$newest}." . $summary
            . "\n         Those have quietly been left undone: no invoice sent, no synchronisation"
            . " run.\n         Inspect: php artisan queue:failed"
            . "\n         Retry:   php artisan queue:retry all");
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
            'provisioning' => 'php artisan queue:work --queue=provisioning (as lavoro_provisioner)',
        ];

        foreach ($workers as $queue => $command) {
            $beat = WorkerHeartbeat::beatFor($queue);

            if ($beat === null) {
                $this->bad("Queue '{$queue}': no heartbeat at all. Is '{$command}' running?"
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
                ? $this->pass("worker for '{$queue}' is running")
                : $this->bad("Queue '{$queue}': the worker runs on older {$stale} than what is here"
                    . ' now. Php holds on to everything at boot, so until a restart it works with'
                    . ' what was there then:'
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

        $evidence = "\n         here stands: " . base_path() . ', code '
            . ($code === '' ? 'onbekend' : substr($code, 0, 8));

        if ($lines !== []) {
            $evidence .= "\n         reporting:   " . implode("\n                      ", $lines);
        }

        if ($running !== []) {
            $evidence .= "\n         running now: " . implode("\n                      ", $running);
        }

        return $evidence . (count($lines) > 1 || count($running) > 1
            ? "\n         More than one process on the same queue: restarting the unit alone leaves"
                . ' the rest running.'
            : '');
    }

    private function checkTenant(Tenant $tenant): void
    {
        $database = $tenant->getInternal('db_name');

        try {
            $tenant->tenancy_db_password
                ? $this->pass('password can be decrypted')
                : $this->bad('no MySQL login -- half created tenant');
        } catch (\Throwable $e) {
            $this->bad('password cannot be decrypted -- was APP_KEY changed?');

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

            $this->pass("database {$database} opens with its own login");
        } catch (\Throwable $e) {
            tenancy()->end();

            $this->bad($this->tenantConnectionComplaint($database, $tenant, $e));
            $this->skip('remaining checks for this tenant');

            return;
        }

        try {

            $missing = collect(['is_plannable_state', 'is_planned_state', 'is_closed_state',
                'is_planning_cancelled_state', 'is_invoiced_state', 'is_incomplete_state'])
                ->reject(fn ($flag) => DB::table('service_order_stages')->where($flag, true)->exists());

            $missing->isEmpty()
                ? $this->pass('a stage for every flag')
                : $this->bad('no stage for: ' . $missing->implode(', '));

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
                ? $this->pass(count($expected) . ' roles present')
                : $this->bad('roles missing: ' . implode(', ', $absent)
                    . '. The seeding did not work; repair with:' . "\n"
                    . "         php artisan tenants:seed --tenants={$tenant->id}");

            $users = User::withTrashed()->pluck('email');
            $known = DB::connection('central')->table('user_tenant_lookups')
                ->where('tenant_id', $tenant->id)->pluck('email');

            $orphan = $users->diff($known);

            $orphan->isEmpty()
                ? $this->pass("{$users->count()} users in the central list")
                : $this->bad($orphan->count() . ' user(s) without a central row -- they cannot log in');

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
                    $this->bad("storage {$disk} is missing");

                    continue;
                }

                $writable = $account === null
                    ? is_writable($path)
                    : $this->userCanWrite($account, $path);

                $writable
                    ? $this->pass("storage {$disk}")
                    : $this->bad("storage {$disk} is not writable for "
                        . ($account ?? 'this account') . ', so uploads fail. Repair with:'
                        . "\n         sudo setfacl -R -m u:{$account}:rwX " . storage_path());
            }

            tenancy()->end();
        } catch (\Throwable $e) {
            tenancy()->end();
            $this->bad('connecting failed: ' . $e->getMessage());
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
            return "database {$database} does not exist, while the customer is in the registry."
                . ' Clean it up or create it again.';
        }

        if ($exists === true) {
            return "database {$database} exists, but this customer's login ({$login}) does not get"
                . ' in. The MySQL account is gone or lost its rights; grant them again with'
                . " lavoro_admin.grant_tenant_access('{$database}', '{$login}').";
        }

        return "database {$database} does not open with this customer's login ({$login}), and whether"
            . ' it exists cannot be established from here: ' . $e->getMessage();
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
        $this->line('Environment');

        foreach (['pcntl', 'posix', 'pdo_mysql'] as $extension) {
            extension_loaded($extension)
                ? $this->pass("PHP-onderdeel {$extension}")
                : $this->bad("PHP extension {$extension} is missing -- the provisioner command"
                    . ' cannot elevate itself then and has to be typed with sudo -u');
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
            ? $this->pass('php may start programs')
            : $this->bad('php.ini has ' . implode(' and ', $blocked) . ' switched off. Without those'
                . ' functions commands do not elevate themselves, and whether they could cannot be'
                . ' checked above -- so those findings say nothing.');

        filled(config('app.key'))
            ? $this->pass('APP_KEY staat ingevuld')
            : $this->bad('APP_KEY is empty -- no customer database password can be read at all');

        if (app()->environment('production')) {
            config('app.debug')
                ? $this->bad('APP_DEBUG is on in production -- error pages then show .env values')
                : $this->pass('APP_DEBUG staat uit');
        }

        config('mail.default') === 'tenant'
            ? $this->pass('MAIL_MAILER=tenant -- every customer sends with its own settings')
            : $this->bad('MAIL_MAILER is ' . config('mail.default') . ' and not "tenant". Everyone'
                . ' verstuurt dan via dezelfde mailbox.');

        filled(config('mail.mailers.landlord.host'))
            ? $this->pass('own mail server for invoices configured')
            : $this->bad('LANDLORD_MAIL_HOST is empty -- invoices to customers cannot be sent');

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
            ? $this->pass('built assets present')
            : $this->bad('public/build/manifest.json is missing -- the build never ran here.'
                . ' Every page then gives a Vite error. Run npm ci && npm run build.');

        $worker_path = public_path('service-worker.js');

        if (!file_exists($worker_path)) {
            $this->bad('public/service-worker.js is missing -- git no longer holds that file,'
                . ' the build makes it. Without it the browser caches nothing any more.'
                . ' Run npm run build.');

            return;
        }

        $revision = $this->gitRevision();

        if ($revision === null) {
            $this->pass('service worker aanwezig');

            return;
        }

        str_contains(File::get($worker_path), "lavoro-cache-{$revision}")
            ? $this->pass('service worker belongs to the deployed code')
            : $this->bad('public/service-worker.js comes from an older build than the code that is'
                . ' here. Browsers then keep serving old files from their cache.'
                . ' Run npm run build.');
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
                ? $this->bad('The configuration is not cached. On a server'
                    . ' php artisan config:cache should run; without it every request reads .env again.')
                : $this->pass('configuration not cached (reads .env directly)');

            return;
        }

        $env = base_path('.env');

        if (file_exists($env) && filemtime($env) > filemtime($cache)) {
            $this->bad('.env was changed after the last config:cache ('
                . date('d-m-Y H:i', filemtime($cache)) . '), so the app still runs on the old'
                . " values.\n         Update with: php artisan config:cache");

            return;
        }

        $this->pass('cached configuration is newer than the last change in .env');
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
            $this->bad('APP_URL is empty or unreadable. Sanctum derives from it which front end'
                . ' belongs to this installation; without that value every request to /api stays'
                . ' unauthenticated and the planner does not work.');

            return;
        }

        /**
         * Not checkable from the command line: whether this is also the address
         * customers have in their browser. If it is off -- www in front, http
         * instead of https, an old domain -- Sanctum does not recognise the
         * request as its own front end and there is no session. So it is
         * spelled out here, to be checked by eye.
         */
        $this->line("       APP_URL is {$url}; requests to /api have to come from exactly that address.");

        if (app()->environment('production') && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->bad("APP_URL points at {$host}, and on a server that is never the address"
                . ' customers arrive on. Requests from the real domain then do not count as its own'
                . ' front end, and everything going over /api -- the planner first of all -- gets'
                . " 'Unauthenticated' back while the ordinary screens work fine.");
        } elseif ($stateful->contains($served)) {
            $this->pass("{$served} counts as its own front end");
        } else {
            $this->bad("According to APP_URL the app runs on {$served}, but that is not in"
                . ' SANCTUM_STATEFUL_DOMAINS (' . $stateful->implode(', ') . ").\n"
                . "         Everything going over /api then gets 'Unauthenticated' back. Add that"
                . ' address and run php artisan config:cache afterwards.');
        }

        /**
         * The tenant is set in the only hook Sanctum offers in that pipeline.
         * Should Sanctum's configuration ever be published again, the default
         * class is back in it and the customer is gone without anything
         * breaking -- except every /api request.
         */
        config('sanctum.middleware.authenticate_session') === TenancyForStatefulApi::class
            ? $this->pass('api requests carry their customer')
            : $this->bad('sanctum.middleware.authenticate_session should be '
                . TenancyForStatefulApi::class . ', but is '
                . var_export(config('sanctum.middleware.authenticate_session'), true)
                . '. Without that hook /api looks for the user in the wrong database.');

        collect(app(Router::class)->getMiddlewareGroups()['api'] ?? [])
            ->contains(EnsureFrontendRequestsAreStateful::class)
            ? $this->pass('api requests may use the session')
            : $this->bad('The api group is missing ' . class_basename(EnsureFrontendRequestsAreStateful::class)
                . ' (statefulApi() in bootstrap/app.php). Without that middleware there is no session'
                . ' on /api and therefore no logged in user.');
    }

    private function checkDrivers(): void
    {
        $expected = [
            'queue.default' => ['database', 'The queue is on %s. Provisioning then runs inside the'
                . ' web request as the wrong account instead of in its own worker.'],
            'session.driver' => ['database', 'The session is on %s and should be on database;'
                . ' otherwise it is not central.'],
            'cache.default' => ['database', 'The cache is on %s and should be on database;'
                . ' the separation per customer hangs on the central cache.'],
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
            $this->bad("The directory {$directory} does not exist; invoices then do not render."
                . ' Create it and give the web server write access.');

            return;
        }

        is_writable($directory)
            ? $this->pass('font directory for invoices')
            : $this->bad("The directory {$directory} is not writable; invoices then do not render.");
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
            $this->skip('free disk space cannot be read');

            return;
        }

        $gigabytes = round($free / (1024 ** 3), 1);
        $percentage = round($free / $total * 100);

        match (true) {
            $percentage < 5 => $this->bad("{$gigabytes} GB free ({$percentage}%) left. With a full"
                . ' disk uploads fail and the database stops writing.'),
            $percentage < 15 => $this->skip("{$gigabytes} GB free ({$percentage}%) left -- keep an eye on it"),
            default => $this->pass("disk space: {$gigabytes} GB free ({$percentage}%)"),
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
            $this->pass('log file is a workable size' . ($megabytes > 0 ? " ({$megabytes} MB)" : ''));

            return;
        }

        $daily = str_contains((string) config('logging.default'), 'daily')
            || in_array('daily', (array) config('logging.channels.stack.channels', []), true);

        $daily
            ? $this->skip("The log file is {$megabytes} MB. It does rotate; the old files may go.")
            : $this->bad("The log file is {$megabytes} MB and keeps growing: LOG_CHANNEL does not"
                . " rotate. Set LOG_STACK=daily in .env and restart php.\n"
                . "         Clear it now: truncate -s 0 {$log}");
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
            ? $this->pass('own company details for the invoice')
            : $this->bad('invoicing details missing: ' . $missing->implode(', ')
                . ' -- fill them in under Catalogus > Facturatie');
    }

    /**
     * The rights of the database accounts are checked by
     * scripts/tenancy/verify-mysql.sh.
     *
     * It tries, as the provisioner, to create a database inside and outside the
     * allowed names, and as the application one that should be refused -- more
     * thoroughly than is possible from here, because that needs root. Only the
     * reference here; writing the same check twice produces two answers that
     * drift apart. scripts/deploy.sh runs it along.
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
        $this->line('Permissions');

        $advice = 'Draai: sudo scripts/tenancy/verify-mysql.sh (gebeurt ook bij elke deploy).';
        $file = storage_path('app/tenancy-privileges.json');

        $outcome = is_readable($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        if (!is_array($outcome) || !isset($outcome['checked_at'])) {
            $this->skip('The rights of the database accounts have never been checked here.'
                . ' That is what proves the separation between the accounts. ' . $advice);

            return;
        }

        $when = CarbonImmutable::parse($outcome['checked_at']);
        $moment = $when->diffForHumans();

        if (($outcome['failed'] ?? 0) > 0) {
            $this->bad("At the last check ({$moment}) there were {$outcome['failed']} problem(s)"
                . ' with the rights of the database accounts. ' . $advice);

            return;
        }

        if (($outcome['skipped'] ?? 0) > 0) {
            $this->skip("The last check ({$moment}) could not verify {$outcome['skipped']} point(s)"
                . ' and therefore proves nothing. ' . $advice);

            return;
        }

        if ($when->isBefore(now()->subDays(self::PRIVILEGES_STALE_AFTER_DAYS))) {
            $this->skip("The rights were last checked {$moment}. " . $advice);

            return;
        }

        /**
         * Checks that did not apply yet -- there is no customer at all, so no
         * customer account to check either -- are not a hole in the check. Worth
         * mentioning though, because as soon as there is a customer a new run
         * says more than this one.
         */
        $pending = (int) ($outcome['not_applicable'] ?? 0);

        $this->pass("rights of the database accounts checked ({$moment})"
            . ($pending > 0 ? ", except {$pending} point(s) that did not exist then" : ''));
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
            $this->bad($stuck->count() . ' request(s) have been stuck for over fifteen minutes. Is'
                . ' "php artisan queue:work --queue=provisioning" running as lavoro_provisioner?');

            return;
        }

        if ($requests->isNotEmpty()) {
            $this->pass($requests->count() . ' request(s) on their way.');

            return;
        }

        /**
         * No requests does not mean the worker runs -- that can only be seen
         * from work that got finished. Without that this path is unproven and
         * not fine.
         */
        TenantProvisioningRequest::on('central')->where('status', 'done')->exists()
            ? $this->pass('No requests waiting; the worker has finished work before.')
            : $this->skip('No requests waiting, and none has ever been finished -- whether the worker'
                . ' runs cannot be established from this.');
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
            $this->pass('No failed requests.');

            return;
        }

        $this->bad($failed->count() . ' failed request(s):');

        foreach ($failed as $request) {
            $this->line("         {$request->action} '{$request->name}': "
                . Str::limit((string) $request->error, 300));
        }

        $this->line('       Solved? Remove them in the admin panel and try again.');
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
            $this->pass("MySQL account {$who} exists and works");
        } elseif ($password !== '') {
            $this->bad("MySQL account {$username} does not log in with the password from .env."
                . ' Does the account exist, and is DB_PROVISIONER_PASSWORD right?');
        } else {
            /**
             * Not being able to log in proves nothing. An account that hangs on
             * a Linux user should refuse here, and an account that does not
             * exist at all does exactly the same. So do not approve this.
             */
            $this->checkProvisionerAccountByElevating($username);
        }

        if ($password !== '') {
            $this->bad("The password of {$username} is in .env. Whoever can read that file -- the"
                . ' web server too -- can drop every customer database with it.');
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
            $this->pass("already running as {$username}; elevating is not needed");

            return;
        }

        ProvisionerConnection::canElevate()
            ? $this->pass("can become {$username} without a password; commands elevate themselves")
            : $this->skip("Cannot become {$username} without a password. Tenant commands then have to"
                . " be typed with 'sudo -u {$username} php artisan ...'. If you would rather not, run:"
                . ' sudo scripts/tenancy/setup-sudoers.sh');
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
            $this->skip('Which account the web server runs as cannot be seen yet: there are no'
                . ' compiled templates. Open a page and run this again.');

            return;
        }

        $log = storage_path('logs/laravel.log');

        if (!file_exists($log)) {
            $this->skip('Cannot establish which account the web server runs as.');

            return;
        }

        if ($this->userCanWrite($account, $log)) {
            $this->pass("web server runs as {$account} and can write its errors down");

            return;
        }

        $this->bad("The web server runs as {$account}, but that account cannot write in"
            . " {$log} (which belongs to " . ($this->ownerOf($log) ?? '?') . ').'
            . ' Every error from a web request then disappears without a trace. Repair with:' . "\n"
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
            $this->skip("Whether {$username} may write in storage/ cannot be seen from here."
                . ' Without that right the first upload of a new customer fails.');

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
            $this->pass("{$username} may write in storage/");

            return;
        }

        if ($status === 2) {
            $this->bad("{$username} does not even reach {$path}; a directory above it does not let"
                . ' them through. Give them passage only, no more than that:' . "\n"
                . collect($this->unreachableAncestors($path))
                    ->map(fn (string $directory) => "         sudo setfacl -m u:{$username}:x {$directory}")
                    ->implode("\n") . $this->setfaclHint());

            return;
        }

        $this->bad("{$username} may not write in {$path}; the folders of a new customer cannot be"
            . " created then. Grant write access with:\n"
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

        return $found === '' ? "\n" . '         (setfacl lives in the acl package: apt install acl)' : '';
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
            $this->skip("Whether the MySQL account {$username} exists cannot be seen from here."
                . " Run 'sudo -u {$username} php artisan tenancy:doctor' to check it.");

            return;
        }

        $socket = (string) config('database.connections.provisioner.unix_socket');
        $database = (string) config('database.connections.provisioner.database');

        if ($socket === '') {
            $this->bad("DB_PROVISIONER_SOCKET is empty, so {$username} would connect over TCP,"
                . ' and this account only comes in through the socket. Put in .env:' . "\n"
                . '         DB_PROVISIONER_SOCKET=' . $this->serverSocket());

            return;
        }

        $status = ProvisionerConnection::phpAsProvisioner(
            'try { ' . $this->provisionerPdo($username) . ' exit(0); } catch (Throwable $e) { exit(1); }'
        );

        $status === 0
            ? $this->pass("MySQL account {$username} exists and comes in through the socket")
            : $this->bad("MySQL account {$username} does not come in through {$socket}. Does the"
                . ' account exist, and does it hang on the Linux user of the same name?'
                . ' Repair: sudo scripts/tenancy/setup-mysql.sh');
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
            $this->bad("tenancy.database.grant_procedure should be 'database.procedure',"
                . " not '{$procedure}'.");

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
            $this->skip("Whether {$procedure} exists and still refuses what it should refuse cannot"
                . ' be seen from here.');

            return;
        }

        match ($status) {
            0 => $this->pass("{$procedure} exists and refuses everything outside the customer namespace"),
            2 => $this->bad("{$procedure} handed out rights on {$forbidden}. It should refuse"
                . ' everything outside the customer namespace; as it is, the provisioner can grant'
                . ' rights on anything. Repair: sudo scripts/tenancy/setup-mysql.sh'),
            default => $this->bad("{$procedure} cannot be called. Without that procedure a new customer"
                . ' gets as far as the database and strands after that.'
                . ' Repair: sudo scripts/tenancy/setup-mysql.sh'),
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
            $this->skip("Cannot check whether the Linux user {$username} exists (posix is missing).");

            return;
        }

        if (posix_getpwnam($username) !== false) {
            $this->pass("Linux user {$username} exists");

            return;
        }

        $password === ''
            ? $this->bad("Linux user {$username} does not exist, while no password is set. Nobody"
                . ' can log in that way and no customer can be created.')
            : $this->bad("Linux user {$username} does not exist. Create them and attach the MySQL"
                . ' account to it, then the password can go out of .env. This does that:'
                . ' sudo scripts/tenancy/setup-mysql.sh --write-env');
    }

    private function checkOrphans(): void
    {
        $this->line('Orphans');

        $ids = Tenant::on('central')->pluck('id');

        $stale = DB::connection('central')->table('user_tenant_lookups')
            ->whereNotIn('tenant_id', $ids)->count();

        $stale === 0 ? $this->pass('no references to vanished tenants')
            : $this->bad("{$stale} row(s) in user_tenant_lookups point at a tenant that no longer exists");

        $prefix = config('tenancy.database.prefix');

        $databases = collect(DB::connection('central')->select(
            'SELECT SCHEMA_NAME AS n FROM information_schema.schemata WHERE SCHEMA_NAME LIKE ?', [$prefix . '%']
        ))->pluck('n');

        $known = Tenant::on('central')->get()->map(fn ($t) => $t->getInternal('db_name'));
        $unknown = $databases->diff($known);

        $unknown->isEmpty() ? $this->pass('no databases without a tenant')
            : $this->bad('database without a tenant: ' . $unknown->implode(', '));

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
            $this->pass('no folders without a tenant');

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
                "folder without a tenant: %s -- %d file(s), %s.\n"
                . '         No customer with this id is in the registry any more, but the contents'
                . " may belong to a customer that was created again.\n"
                . '         Look inside first (%s), then clear it with:'
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
