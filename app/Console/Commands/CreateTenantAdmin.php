<?php

namespace App\Console\Commands;

use App\Models\Central\UserTenantLookup;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Gives an existing tenant an admin, or sets an existing one's password again.
 * tenant:create does this for a new customer; tenant:setup-existing
 * deliberately leaves an adopted database without one.
 */
class CreateTenantAdmin extends Command
{
    protected $signature = 'tenant:admin
        {tenant : Id of naam van de tenant}
        {email : Het e-mailadres van de beheerder}
        {--password= : Leeg laten genereert er een}
        {--name=Beheerder}';

    protected $description = 'Creates an admin for a tenant, or resets their password';

    public function handle(): int
    {
        $needle = $this->argument('tenant');

        $tenant = Tenant::on('central')->find($needle)
            ?? Tenant::on('central')->where('name', $needle)->first();

        if (!$tenant) {
            $this->error("No tenant found for '{$needle}'.");

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $password = $this->option('password') ?: Str::password(16);

        /** An address belongs to one tenant; otherwise logging in does not know where to go. */
        $lookup = UserTenantLookup::on('central')->find($email);

        if ($lookup && $lookup->tenant_id !== $tenant->id) {
            $this->error("{$email} is already in use at another tenant.");

            return self::FAILURE;
        }

        /**
         * Through the helper: it puts back what was there. With a bare
         * tenancy()->end() everything after this command -- or after this test
         * -- suddenly runs without a tenant.
         */
        $failure = Tenancy::within($tenant, function () use ($tenant, $email, $password) {
            $role = Role::where('name', 'admin')->first();

            if (!$role) {
                return 'De rol admin ontbreekt in deze database.';
            }

            $user = User::where('email', $email)->first();

            if ($user) {
                $user->update(['password' => Hash::make($password)]);
                $user->roles()->syncWithoutDetaching($role->id);
                $this->info("Password of {$email} reset and admin role confirmed.");

                return null;
            }

            /** Being an admin is a role and not a column; that link is the whole point. */
            User::create([
                'name' => $this->option('name'),
                'email' => $email,
                'password' => Hash::make($password),
                'seat_type' => 'office',
            ])->roles()->attach($role->id);

            $this->info("Admin {$email} created for {$tenant->name}.");

            return null;
        });

        if ($failure) {
            $this->error($failure);

            return self::FAILURE;
        }

        $this->line('  password: ' . $password);

        return self::SUCCESS;
    }
}
