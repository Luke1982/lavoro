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
    /**
     * De instellingen zoals ze waren voordat er werd omgezet, zodat het terug
     * kan. Zonder dat blijft een verzoek waarin dit misgaat kapot achter: ook
     * het wegschrijven van de sessie en het tonen van de foutmelding lopen dan
     * over dezelfde verbinding, en die is dan van niemand meer.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $previous = [];

    public static function use(): void
    {
        $provisioner = config('database.connections.provisioner');

        foreach (self::switchable() as $name) {
            self::$previous[$name] ??= config("database.connections.{$name}");

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

    /** Terug naar de instellingen van voor use(). */
    public static function restore(): void
    {
        foreach (self::$previous as $name => $settings) {
            Config::set("database.connections.{$name}", $settings);
            DB::purge($name);
        }

        self::$previous = [];
    }

    /** @return array<int, string> */
    private static function switchable(): array
    {
        return ['central', config('tenancy.database.template_tenant_connection', 'mysql')];
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
    /**
     * Lukt het niet, dan gaat de verbinding eerst terug naar wat hij was. Blijft
     * hij omgezet staan, dan sneuvelt de rest van het verzoek ook -- inclusief
     * het wegschrijven van de sessie, en daarmee de foutmelding die zou moeten
     * uitleggen wat er aan de hand is.
     */
    public static function assertUsable(): void
    {
        if (self::works()) {
            return;
        }

        $advice = self::advice();

        self::restore();

        throw new RuntimeException($advice);
    }

    public static function advice(): string
    {
        $name = (string) config('database.connections.provisioner.username');

        return "Kan niet verbinden als {$name} (nu draaiend als '" . self::linuxUser() . "')."
            . " Draai dit als: sudo -u {$name} php artisan <commando>, of draai eenmalig"
            . ' sudo scripts/tenancy/setup-sudoers.sh zodat het zichzelf verheft.'
            . ' De worker van het beheerpaneel hoort al als deze gebruiker te draaien;'
            . ' zie docs/tenancy-bediening.md.';
    }

    /**
     * Mag deze gebruiker zonder wachtwoord de provisioner worden?
     *
     * Dat hangt aan de sudo-regel die scripts/tenancy/setup-sudoers.sh neerzet. Staat hij er, dan
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

        return self::phpAsProvisioner('exit(0);') === 0;
    }

    /**
     * Draait een stukje php als de provisioner en geeft de exitcode terug, of
     * null als er niet eens een sudo is om het mee te proberen.
     *
     * Via php en niet via 'true' of 'test': de sudo-regel geeft rechten op de
     * php-binary en op niets anders. Een proef met een ander programma valt
     * daarbuiten en zou dus 'nee' zeggen terwijl het verheffen zelf gewoon mag.
     * De proef moet precies datgene doen wat er straks echt gebeurt.
     */
    public static function phpAsProvisioner(string $code): ?int
    {
        $sudo = trim((string) shell_exec('command -v sudo 2>/dev/null'));

        if (!$sudo) {
            return null;
        }

        $name = (string) config('database.connections.provisioner.username');

        exec($sudo . ' -n -u ' . escapeshellarg($name) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -r ' . escapeshellarg($code) . ' 2>/dev/null', $ignored, $status);

        return $status;
    }

    public static function linuxUser(): string
    {
        return function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'onbekend')
            : 'onbekend';
    }
}
