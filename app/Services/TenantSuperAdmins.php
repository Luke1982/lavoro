<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The accounts MajorLabel itself can enter a customer's database with.
 *
 * Only from the admin panel: the customer's application does not know the role
 * in its roles screen, cannot create it and cannot grant it. Needs no
 * provisioner rights -- it only writes in existing tables, it creates no
 * database.
 */
class TenantSuperAdmins
{
    public function create(Tenant $tenant, string $email, string $password = '', string $name = 'MajorLabel'): string
    {
        $password = $password ?: Str::password(16);

        /** An address points at one tenant when logging in. */
        $lookup = DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->first();

        if ($lookup && $lookup->tenant_id !== $tenant->id) {
            throw new Refusal("{$email} is al in gebruik bij een andere tenant.");
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
             * Without the global scope: it hides these accounts from the
             * customer, and the admin panel is precisely where they are
             * managed. The panel also runs on the landlord guard, so the
             * exception for "I am a super admin myself" does not apply here.
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
