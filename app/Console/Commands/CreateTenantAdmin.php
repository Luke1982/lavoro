<?php

namespace App\Console\Commands;

use App\Models\Central\UserTenantLookup;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Geeft een bestaande tenant een beheerder, of zet het wachtwoord van een
 * bestaande opnieuw. tenant:create doet dit voor een nieuwe klant;
 * tenant:setup-existing laat een overgenomen database juist zonder achter.
 */
class CreateTenantAdmin extends Command
{
    protected $signature = 'tenant:admin
        {tenant : Id of naam van de tenant}
        {email : Het e-mailadres van de beheerder}
        {--password= : Leeg laten genereert er een}
        {--name=Beheerder}';

    protected $description = 'Maakt een beheerder voor een tenant, of zet zijn wachtwoord opnieuw';

    public function handle(): int
    {
        $needle = $this->argument('tenant');

        $tenant = Tenant::on('central')->find($needle)
            ?? Tenant::on('central')->where('name', $needle)->first();

        if (!$tenant) {
            $this->error("Geen tenant gevonden op '{$needle}'.");

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $password = $this->option('password') ?: Str::password(16);

        /** Een adres hoort bij één tenant; anders weet het inloggen niet waarheen. */
        $lookup = UserTenantLookup::on('central')->find($email);

        if ($lookup && $lookup->tenant_id !== $tenant->id) {
            $this->error("{$email} is al in gebruik bij een andere tenant.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $role = Role::where('name', 'admin')->first();

            if (!$role) {
                $this->error('De rol admin ontbreekt in deze database.');

                return self::FAILURE;
            }

            $user = User::where('email', $email)->first();

            if ($user) {
                $user->update(['password' => Hash::make($password)]);
                $user->roles()->syncWithoutDetaching($role->id);
                $this->info("Wachtwoord van {$email} opnieuw gezet en beheerdersrol bevestigd.");
            } else {
                $user = User::create([
                    'name' => $this->option('name'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'seat_type' => 'office',
                ]);
                $user->roles()->attach($role->id);
                $this->info("Beheerder {$email} aangemaakt voor {$tenant->name}.");
            }
        } finally {
            tenancy()->end();
        }

        $this->line('  wachtwoord: ' . $password);

        return self::SUCCESS;
    }
}
