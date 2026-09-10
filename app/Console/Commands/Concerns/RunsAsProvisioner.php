<?php

namespace App\Console\Commands\Concerns;

use App\Support\ProvisionerConnection;

/**
 * Lets a command speak as lavoro_provisioner.
 *
 * That account should hang on the Linux user of the same name (auth_socket),
 * without a password: then only that user can create and drop customer
 * databases, and there is no password anywhere for a web request to do the
 * same with.
 *
 * The command elevates itself when the sudo rule is there; otherwise it says
 * which rule you need. scripts/tenancy/setup-sudoers.sh puts that rule in
 * place.
 */
trait RunsAsProvisioner
{
    protected function runAsProvisioner(): bool
    {
        $this->elevate();

        ProvisionerConnection::use();

        if (ProvisionerConnection::works()) {
            return true;
        }

        $this->error(ProvisionerConnection::advice());

        return false;
    }

    /**
     * Starting again as the provisioner, when that is allowed without a
     * password. If it is not, it simply carries on: maybe we already run as the
     * right user, or a password is still set in the environment.
     */
    private function elevate(): void
    {
        $name = (string) config('database.connections.provisioner.username');

        if (ProvisionerConnection::linuxUser() === $name) {
            return;
        }

        if (filled(config('database.connections.provisioner.password'))) {
            return;
        }

        $sudo = trim((string) shell_exec('command -v sudo 2>/dev/null'));

        if (!$sudo || !function_exists('pcntl_exec')) {
            return;
        }

        if (!ProvisionerConnection::canElevate()) {
            return;
        }

        /**
         * pcntl_exec and not passthru: argv travels as an array, so a customer
         * called "Spee B.V." survives it without quoting. It also replaces this
         * process, so the exit code is the real one and Ctrl-C arrives in the
         * right place.
         */
        pcntl_exec($sudo, array_merge(
            ['-n', '-u', $name, PHP_BINARY, base_path('artisan')],
            array_slice($_SERVER['argv'] ?? [], 1),
        ));
    }
}
