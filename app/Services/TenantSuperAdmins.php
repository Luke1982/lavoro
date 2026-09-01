<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * De accounts waarmee MajorLabel zelf in de database van een klant kan.
 *
 * Alleen vanuit het beheerpaneel: de applicatie van de klant kent de rol niet
 * in zijn rollenscherm, kan hem niet aanmaken en niet toekennen. Vraagt geen
 * provisioner-rechten -- er wordt alleen in bestaande tabellen geschreven,
 * geen database aangemaakt.
 */
class TenantSuperAdmins
{
    public function create(Tenant $tenant, string $email, string $password = '', string $name = 'MajorLabel'): string
    {
        $password = $password ?: Str::password(16);

        /** Een adres wijst bij het inloggen één tenant aan. */
        $lookup = DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->first();

        if ($lookup && $lookup->tenant_id !== $tenant->id) {
            throw new RuntimeException("{$email} is al in gebruik bij een andere tenant.");
        }

        return Tenancy::within($tenant, function () use ($email, $password, $name) {
            $role = Role::firstOrCreate(['name' => Role::SUPERADMIN]);

            $user = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($user) {
                $user->update(['password' => Hash::make($password)]);
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'seat_type' => 'office',
                ]);
            }

            $user->roles()->syncWithoutDetaching($role->id);

            return $password;
        });
    }

    /** @return array<int, array{id: int, name: string, email: string}> */
    public function all(Tenant $tenant): array
    {
        return Tenancy::within($tenant, function () {
            $role = Role::where('name', Role::SUPERADMIN)->first();

            /**
             * Zonder de globale scope: die verbergt deze accounts voor de
             * klant, en het beheerpaneel is juist de plek waar ze beheerd
             * worden. Het paneel draait bovendien op de landlord-guard, dus de
             * uitzondering voor "ik ben zelf superbeheerder" gaat hier niet op.
             */
            if (!$role) {
                return [];
            }

            return $role->users()->withoutGlobalScopes()
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->all();
        });
    }

    public function remove(Tenant $tenant, int $user_id): void
    {
        Tenancy::within($tenant, function () use ($user_id) {
            $user = User::withoutGlobalScopes()->find($user_id);

            if ($user && $user->isSuperAdmin()) {
                $email = $user->email;
                $user->forceDelete();
                DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->delete();
            }
        });
    }
}
