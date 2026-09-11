<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Switches the database connections over to the provisioner account: the only
 * one allowed to create and drop customer databases.
 */
final class ProvisionerConnection
{
    /**
     * The settings as they were before switching, so it can go back. Without
     * that a request in which this goes wrong is left broken: writing the
     * session and showing the error message run over the same connection too,
     * and that one belongs to nobody by then.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $previous = [];

    /**
     * Two connections, not one. 'central' for the tenants table, and the
     * template connection because stancl's database manager runs on it:
     * DatabaseConfig::manager() calls setConnection(getTemplateConnectionName()),
     * which is DB_CONNECTION. Switching only 'central' leaves creating the
     * database and the user running as lavoro_app, and that is not allowed.
     */
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

    /** Back to the settings from before use(). */
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

    /**
     * If it does not work, the connection first goes back to what it was. Leave
     * it switched over and the rest of the request dies as well -- including
     * writing the session, and with it the error message that should explain
     * what is going on.
     *
     * @throws RuntimeException
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

        return "Cannot connect as {$name} (running as '" . self::linuxUser() . "' now)."
            . " Run this as: sudo -u {$name} php artisan <command>, or run"
            . ' sudo scripts/tenancy/setup-sudoers.sh once so it elevates itself.'
            . ' The admin panel\'s worker should already run as this user;'
            . ' see docs/tenancy-operations.md.';
    }

    /**
     * May this user become the provisioner without a password?
     *
     * That hangs on the sudo rule scripts/tenancy/setup-sudoers.sh puts in
     * place. With it, the tenant commands elevate themselves; without it you
     * have to type 'sudo -u lavoro_provisioner' yourself. Both work, but it is
     * the difference between a command that works and one that returns an
     * explanation, so you want to know which of the two you have.
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
     * Runs a snippet of php as the provisioner and returns the exit code, or
     * null when there is not even a sudo to try it with.
     *
     * Through php and not through 'true' or 'test': the sudo rule grants rights
     * on the php binary and on nothing else. A probe with another program falls
     * outside it and would say "no" while elevating itself is perfectly
     * allowed. The probe has to do exactly what will really happen later.
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
