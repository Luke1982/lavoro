<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * What has to be backed up, and what to reach it with.
 *
 * The deploy script fetched this through tinker at first. That is a shell
 * around a REPL: it writes messages of its own, swallows exit() and returns an
 * exit code that says nothing about whether it worked. In a script that should
 * stop at every error that is exactly wrong.
 *
 * Every line reads: DUMP<tab>database<tab>user<tab>password<tab>host<tab>port
 * or OVERSLAAN<tab>name for a customer whose database will not open.
 */
class TenancyBackupTargets extends Command
{
    protected $signature = 'tenancy:backup-targets';

    protected $description = 'Lists the databases that have to be backed up, with their credentials';

    public function handle(): int
    {
        $central = config('database.connections.central');

        $this->line($this->row($central['database'], $central['username'], $central['password'], $central));

        foreach (Tenant::on('central')->get() as $tenant) {
            if (!Tenancy::reachable($tenant)) {
                $this->line("OVERSLAAN\t" . $tenant->name);

                continue;
            }

            $this->line($this->row(
                $tenant->getInternal('db_name'),
                $tenant->tenancy_db_username,
                $tenant->tenancy_db_password,
                $central,
            ));
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $central */
    private function row(string $database, ?string $user, ?string $password, array $central): string
    {
        return implode("\t", [
            'DUMP', $database, (string) $user, (string) $password,
            $central['host'], $central['port'],
        ]);
    }
}
