<?php

namespace App\Console\Commands;

use App\Models\GeneralSetting;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * The settings a dedicated installation keeps in its .env, written into the
 * customer where this installation keeps them: in their own database.
 *
 * Importing copies a database, and these were never in one. On a dedicated
 * installation the mail server, the Microsoft 365 registration and the
 * SnelStart keys are environment settings, because there was only ever one
 * company to send for. Here every customer sends with their own, so they live
 * in general_settings.
 *
 * Without this step a customer arrives with an empty Technisch beheer screen
 * and sends no mail at all -- which is deliberate (sending from the wrong
 * company's mailbox would be worse) but reads as something that broke in the
 * move.
 */
class ImportTenantSettings extends Command
{
    protected $signature = 'tenant:import-settings
        {database : the customer database, as in tenants:list}
        {--from= : the old installation\'s .env file, or the folder holding it}
        {--overwrite : replace settings this customer already has}
        {--dry-run : say what would be set, change nothing}';

    protected $description = 'Takes mail, Microsoft 365 and SnelStart settings from a dedicated installation\'s .env';

    /**
     * Every setting this installation keeps per customer, and the environment
     * setting it came from.
     *
     * @var array<string, string>
     */
    private const FROM_ENV = [
        'mail_from_address' => 'MAIL_FROM_ADDRESS',
        'mail_from_name' => 'MAIL_FROM_NAME',

        'graph_azure_tenant_id' => 'GRAPH_TENANT_ID',
        'graph_client_id' => 'GRAPH_CLIENT_ID',
        'graph_client_secret' => 'GRAPH_CLIENT_SECRET',
        'graph_user_id' => 'GRAPH_USER_ID',

        'mail_smtp_host' => 'MAIL_HOST',
        'mail_smtp_port' => 'MAIL_PORT',
        'mail_smtp_username' => 'MAIL_USERNAME',
        'mail_smtp_password' => 'MAIL_PASSWORD',
        'mail_smtp_scheme' => 'MAIL_SCHEME',

        'snelstart_client_key' => 'SNELSTART_CLIENT_KEY',
        'snelstart_subscription_key' => 'SNELSTART_SUBSCRIPTION_KEY',
    ];

    /** What is never printed back, whatever happens. */
    private const SECRET = [
        'graph_client_secret', 'mail_smtp_password',
        'snelstart_client_key', 'snelstart_subscription_key',
    ];

    public function handle(): int
    {
        $tenant = Tenant::on('central')
            ->where('data->tenancy_db_name', $this->argument('database'))->first();

        if (!$tenant) {
            $this->error('No customer is registered on ' . $this->argument('database') . '.');

            return self::FAILURE;
        }

        $file = $this->envFile();

        if (!$file) {
            return self::FAILURE;
        }

        $environment = $this->read($file);
        $wanted = $this->settingsFrom($environment);

        if ($wanted === []) {
            $this->warn('Nothing to take over: that .env holds no mail, Microsoft 365 or SnelStart settings.');
            $this->line('  Fill them in by hand under Technisch beheer, inside the customer.');

            return self::SUCCESS;
        }

        return $this->write($tenant, $wanted);
    }

    private function envFile(): ?string
    {
        $given = (string) $this->option('from');

        if ($given === '') {
            $this->error('--from is required: the old installation\'s .env, or the folder it is in.');

            return null;
        }

        $file = is_dir($given) ? rtrim($given, '/') . '/.env' : $given;

        if (!is_readable($file)) {
            $this->error("Cannot read {$file}. Root may be needed: it belongs to another account.");

            return null;
        }

        return $file;
    }

    /** @return array<string, string> */
    private function read(string $file): array
    {
        $found = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            /** Quotes around a value, and anything after a # that follows it. */
            $value = trim($value);
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            $value = trim($value, "\"'");

            $found[trim($key)] = $value;
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function settingsFrom(array $environment): array
    {
        $wanted = [];

        foreach (self::FROM_ENV as $setting => $variable) {
            $value = trim((string) ($environment[$variable] ?? ''));

            if ($value !== '' && strtolower($value) !== 'null') {
                $wanted[$setting] = $value;
            }
        }

        /**
         * Which way this customer sends. Read from what is actually filled in
         * rather than from MAIL_MAILER, which on these installations is as
         * often 'smtp' with a Microsoft registration next to it as the other
         * way round.
         */
        if (isset($wanted['graph_client_id'], $wanted['graph_azure_tenant_id'])) {
            $wanted['mail_transport'] = 'graph';
        } elseif (isset($wanted['mail_smtp_host'])) {
            $wanted['mail_transport'] = 'smtp';
        }

        /** The mailbox to send from, when the registration does not name one. */
        if (($wanted['mail_transport'] ?? '') === 'graph' && !isset($wanted['graph_user_id'])
            && isset($wanted['mail_from_address'])) {
            $wanted['graph_user_id'] = $wanted['mail_from_address'];
        }

        if (isset($wanted['mail_smtp_scheme'])) {
            $wanted['mail_smtp_scheme'] = in_array($wanted['mail_smtp_scheme'], ['smtp', 'smtps'], true)
                ? $wanted['mail_smtp_scheme']
                : (($wanted['mail_smtp_port'] ?? '') === '465' ? 'smtps' : 'smtp');
        }

        return $wanted;
    }

    /** @param  array<string, string>  $wanted */
    private function write(Tenant $tenant, array $wanted): int
    {
        $set = [];
        $kept = [];

        Tenancy::within($tenant, function () use ($wanted, &$set, &$kept) {
            foreach ($wanted as $key => $value) {
                $existing = GeneralSetting::get($key);

                if (filled($existing) && !$this->option('overwrite')) {
                    $kept[] = $key;

                    continue;
                }

                if (!$this->option('dry-run')) {
                    GeneralSetting::set($key, $value);
                }

                $set[] = $key;
            }
        });

        $this->info(($this->option('dry-run') ? 'Would set' : 'Set') . ' for ' . $tenant->name . ':');

        foreach ($set as $key) {
            $this->line('  ' . $key . ' = ' . (in_array($key, self::SECRET, true) ? '(secret)' : $wanted[$key]));
        }

        if ($kept !== []) {
            $this->line('');
            $this->warn('  Left alone, this customer already has them: ' . implode(', ', $kept));
            $this->line('  Use --overwrite to replace them.');
        }

        $this->line('');
        $this->line('  Check it under Technisch beheer, and send a test mail from there.');

        return self::SUCCESS;
    }
}
