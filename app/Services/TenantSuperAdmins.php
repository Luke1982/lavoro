<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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

        tenancy()->initialize($tenant);

        try {
            $role = Role::firstOrCreate(['name' => Role::SUPERADMIN]);

            $user = User::where('email', $email)->first();

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
        } finally {
            tenancy()->end();
        }

        return $password;
    }

    /** @return array<int, array{id: int, name: string, email: string}> */
    public function all(Tenant $tenant): array
    {
        tenancy()->initialize($tenant);

        try {
            $role = Role::where('name', Role::SUPERADMIN)->first();

            $users = $role
                ? $role->users()->get(['users.id', 'users.name', 'users.email'])
                    ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                    ->all()
                : [];
        } finally {
            tenancy()->end();
        }

        return $users;
    }

    public function remove(Tenant $tenant, int $user_id): void
    {
        tenancy()->initialize($tenant);

        try {
            $user = User::find($user_id);

            if ($user && $user->isSuperAdmin()) {
                $email = $user->email;
                $user->forceDelete();
                DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->delete();
            }
        } finally {
            tenancy()->end();
        }
    }
}
