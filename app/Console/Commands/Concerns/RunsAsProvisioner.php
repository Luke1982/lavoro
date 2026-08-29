<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

trait RunsAsProvisioner
{
    /**
     * Laat de provisioning-commando's als lavoro_provisioner praten.
     *
     * Twee verbindingen, niet één. 'central' voor de tenants-tabel, en de
     * template-verbinding omdat stancl's database manager daarop draait:
     * DatabaseConfig::manager() doet setConnection(getTemplateConnectionName()),
     * en dat is DB_CONNECTION. Alleen 'central' omzetten laat het aanmaken van
     * de database en de gebruiker als lavoro_app lopen, en dat mag niet.
     */
    protected function runAsProvisioner(): void
    {
        $username = config('database.connections.provisioner.username');
        $password = config('database.connections.provisioner.password');

        foreach (['central', config('tenancy.database.template_tenant_connection', 'mysql')] as $name) {
            Config::set("database.connections.{$name}", array_merge(
                config("database.connections.{$name}"),
                ['username' => $username, 'password' => $password],
            ));

            DB::purge($name);
        }
    }
}
