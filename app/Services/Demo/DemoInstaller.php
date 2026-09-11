<?php

namespace App\Services\Demo;

use App\Exceptions\Refusal;
use App\Models\Central\UserTenantLookup;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use App\Support\Tenancy;
use Closure;
use Database\Seeders\Demo\DemoSeeder;

/**
 * Throws the demo tenant away and builds a fresh one.
 *
 * Every night, so a demo always starts from the same clean state, with a
 * planning around today instead of around the day it was first installed --
 * and whatever someone clicked together during yesterday's demo is gone.
 *
 * Needs the provisioner: dropping and creating a database is its work. The
 * command elevates itself; the nightly job runs on the provisioning queue.
 */
final class DemoInstaller
{
    public const NAME = 'Demo';

    public const LOGIN = 'demo@lavoro.demo';

    public const PASSWORD = 'demo';

    /**
     * Business, with every module, so there is nothing a demo has to skip. Its
     * four office seats do not cover the office roles, so a few extra: every
     * role has a face to switch to.
     */
    private const PACKAGE = 'business';

    private const MODULES = ['quotes', 'invoices', 'assistant'];

    private const EXTRA_OFFICE_SEATS = 3;

    public function __construct(private TenantProvisioner $provisioner) {}

    /**
     * A demo, or what is left of one.
     *
     * The mark is set once the tenant exists, and creating it runs every
     * migration first. Stop the command halfway -- it prints nothing for a
     * minute on a slow server, so people do -- and a tenant called Demo stays
     * behind without the mark, which the next run then refused as a real
     * customer. What tells them apart is who can log in: a half-made demo has
     * nobody, or only demo addresses; a real customer called Demo has its own.
     */
    private function isOurs(Tenant $tenant): bool
    {
        if ($tenant->isDemo()) {
            return true;
        }

        return $tenant->name === self::NAME
            && !UserTenantLookup::on('central')->where('tenant_id', $tenant->id)
                ->where('email', 'not like', '%@lavoro.demo')->exists();
    }

    /**
     * @param  (Closure(string): void)|null  $progress  told what is happening, for a command to show
     */
    public function install(?Closure $progress = null): Tenant
    {
        $tell = fn (string $message) => $progress ? $progress($message) : null;

        foreach (Tenant::on('central')->get()->filter(fn (Tenant $tenant) => $this->isOurs($tenant)) as $previous) {
            $tell('removing the previous demo...');
            $this->provisioner->destroy($previous);
        }

        /**
         * A real customer called Demo would own the same database name. That one
         * is not ours to throw away, and the provisioner refuses to create over
         * it -- say which it is instead of leaving that to a database error.
         */
        if (Tenant::on('central')->where('name', self::NAME)->exists()) {
            throw new Refusal('A customer called "' . self::NAME . '" already exists and is not the demo.'
                . ' It is not overwritten.');
        }

        $tell('creating the tenant: database, login and every migration...');
        $started = microtime(true);

        ['tenant' => $tenant] = $this->provisioner->create(
            name: self::NAME,
            email: self::LOGIN,
            password: self::PASSWORD,
            package: self::PACKAGE,
            modules: self::MODULES,
        );

        $tenant->demo = true;
        $tenant->extra_office_seats = self::EXTRA_OFFICE_SEATS;
        $tenant->save();

        $tell(sprintf('tenant created (%.0f s)', microtime(true) - $started));

        Tenancy::within($tenant, fn () => (new DemoSeeder(progress: $progress))->run());

        return $tenant;
    }
}
