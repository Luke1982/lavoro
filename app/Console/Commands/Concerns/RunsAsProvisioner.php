<?php

namespace App\Console\Commands\Concerns;

use App\Support\ProvisionerConnection;

/**
 * Laat een commando praten als lavoro_provisioner.
 *
 * Dat account hoort aan de Linux-gebruiker met dezelfde naam te hangen
 * (auth_socket), zonder wachtwoord: dan kan alleen die gebruiker databases van
 * klanten maken en weggooien, en staat er nergens een wachtwoord waarmee een
 * webverzoek hetzelfde zou kunnen.
 *
 * Het commando verheft zichzelf als de sudo-regel uit taak 2 stap 2b er is;
 * anders zegt het welke regel je moet typen.
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
     * Opnieuw starten als de provisioner, als dat zonder wachtwoord mag. Lukt
     * dat niet, dan gaat het gewoon door: misschien draaien we al als de juiste
     * gebruiker, of staat er nog een wachtwoord in de omgeving.
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

        exec($sudo . ' -n -u ' . escapeshellarg($name) . ' true 2>/dev/null', $ignored, $status);

        if ($status !== 0) {
            return;
        }

        /**
         * pcntl_exec en geen passthru: argv gaat als array mee, dus een klant
         * die "Spee B.V." heet overleeft het zonder aanhalingstekens. Het
         * vervangt bovendien dit proces, zodat de exitcode de echte is en
         * Ctrl-C op de juiste plek aankomt.
         */
        pcntl_exec($sudo, array_merge(
            ['-n', '-u', $name, PHP_BINARY, base_path('artisan')],
            array_slice($_SERVER['argv'] ?? [], 1),
        ));
    }
}
