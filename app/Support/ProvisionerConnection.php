<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zet de databaseverbindingen om naar het provisioner-account: het enige dat
 * databases van klanten mag maken en weggooien.
 */
final class ProvisionerConnection
{
    /**
     * Twee verbindingen, niet één. 'central' voor de tenants-tabel, en de
     * template-verbinding omdat stancl's database manager daarop draait:
     * DatabaseConfig::manager() doet setConnection(getTemplateConnectionName()),
     * en dat is DB_CONNECTION. Alleen 'central' omzetten laat het aanmaken van
     * de database en de gebruiker als lavoro_app lopen, en dat mag niet.
     */
    public static function use(): void
    {
        $provisioner = config('database.connections.provisioner');

        foreach (['central', config('tenancy.database.template_tenant_connection', 'mysql')] as $name) {
            Config::set("database.connections.{$name}", array_merge(
                config("database.connections.{$name}"),
                [
                    'username' => $provisioner['username'],
                    'password' => $provisioner['password'],
                    'unix_socket' => $provisioner['unix_socket'] ?? '',
                    'host' => $provisioner['host'] ?? null,
                ],
            ));

            DB::purge($name);
        }
    }

    public static function works(): bool
    {
        try {
            DB::connection('central')->select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @throws RuntimeException */
    public static function assertUsable(): void
    {
        if (self::works()) {
            return;
        }

        throw new RuntimeException(self::advice());
    }

    public static function advice(): string
    {
        $name = (string) config('database.connections.provisioner.username');

        return "Kan niet verbinden als {$name} (nu draaiend als '" . self::linuxUser() . "')."
            . " Draai dit als: sudo -u {$name} php artisan <commando>, of installeer"
            . ' /etc/sudoers.d/lavoro-admin (plan, taak 2 stap 2b) zodat het zichzelf verheft.'
            . ' De worker van het beheerpaneel hoort al als deze gebruiker te draaien;'
            . ' zie docs/tenancy-bediening.md.';
    }

    /**
     * Mag deze gebruiker zonder wachtwoord de provisioner worden?
     *
     * Dat hangt aan de sudo-regel uit taak 2 stap 2b. Staat hij er, dan
     * verheffen de tenant-commando's zichzelf; staat hij er niet, dan moet je
     * er zelf 'sudo -u lavoro_provisioner' voor typen. Allebei werkt, maar het
     * is het verschil tussen een commando dat werkt en een dat een uitleg
     * teruggeeft, dus je wilt weten welke van de twee je hebt.
     */
    public static function canElevate(): bool
    {
        $name = (string) config('database.connections.provisioner.username');

        if (self::linuxUser() === $name) {
            return true;
        }

        $sudo = trim((string) shell_exec('command -v sudo 2>/dev/null'));

        if (!$sudo) {
            return false;
        }

        exec($sudo . ' -n -u ' . escapeshellarg($name) . ' true 2>/dev/null', $ignored, $status);

        return $status === 0;
    }

    public static function linuxUser(): string
    {
        return function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'onbekend')
            : 'onbekend';
    }
}
