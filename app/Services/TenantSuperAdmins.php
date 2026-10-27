<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\TenantSuperAdmin;
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
 *
 * Kept in the landlord database, because that is whose they are. The account
 * inside the customer's own database is a copy of that record, and anything
 * that replaces that database -- importing their old installation again,
 * restoring a backup -- throws the copy away. sync() writes it back.
 *
 * The copy is what the application runs on: the role, the policies and
 * Gate::before all work on a user in the customer's database, and that does not
 * change.
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

        $elsewhere = TenantSuperAdmin::where('email', $email)
            ->where('tenant_id', '!=', $tenant->id)->first();

        if ($elsewhere) {
            throw new Refusal("{$email} is al superbeheerder bij een andere tenant.");
        }

        $record = TenantSuperAdmin::updateOrCreate(
            ['email' => $email],
            ['tenant_id' => $tenant->id, 'name' => $name, 'password' => Hash::make($password)],
        );

        $this->copyIntoTheTenant($tenant, $record);

        return $password;
    }

    /**
     * Read from the landlord database, so the panel shows them even when the
     * customer's own database cannot be opened -- which is one of the moments
     * you most want to know who can still get in.
     *
     * An account that holds the role inside the customer's database but is not
     * written down here is taken over into this list. That is how the ones from
     * before this was kept centrally arrive, and how one that somebody granted
     * by hand does: the panel would otherwise show nothing while an account
     * with every right exists, which is the worse of the two mistakes.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public function all(Tenant $tenant): array
    {
        $this->adoptWhatIsOnlyInTheTenant($tenant);

        return TenantSuperAdmin::where('tenant_id', $tenant->id)
            ->orderBy('email')
            ->get(['id', 'name', 'email'])
            ->map(fn (TenantSuperAdmin $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
            ])
            ->all();
    }

    /**
     * Super admins that exist in the customer's database and nowhere else, kept
     * with the password they already have.
     *
     * A customer whose database cannot be opened is not an error here: then
     * there is nothing to take over, and what is written down stays readable.
     */
    private function adoptWhatIsOnlyInTheTenant(Tenant $tenant): void
    {
        try {
            $found = Tenancy::within($tenant, function () {
                $role = Role::where('name', Role::SUPERADMIN)->first();

                if (!$role) {
                    return [];
                }

                return $role->users()->withoutGlobalScopes()
                    ->get(['users.name', 'users.email', 'users.password'])
                    ->all();
            });
        } catch (\Throwable $e) {
            return;
        }

        foreach ($found as $user) {
            if (TenantSuperAdmin::where('email', $user->email)->exists()) {
                continue;
            }

            TenantSuperAdmin::create([
                'tenant_id' => $tenant->id,
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password,
            ]);
        }
    }

    /**
     * Writes every one of this customer's super admins into their database
     * again: after an import, after a restore, or whenever the copy is missing.
     *
     * @return int how many were put back
     */
    public function sync(Tenant $tenant): int
    {
        $records = TenantSuperAdmin::where('tenant_id', $tenant->id)->get();

        foreach ($records as $record) {
            $this->copyIntoTheTenant($tenant, $record);
        }

        return $records->count();
    }

    /** Takes the id of the row in the landlord database, which is what the panel lists. */
    public function remove(Tenant $tenant, int $id): void
    {
        $record = TenantSuperAdmin::where('tenant_id', $tenant->id)->find($id);

        if (!$record) {
            return;
        }

        $email = $record->email;
        $record->delete();

        Tenancy::within($tenant, function () use ($email) {
            $user = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($user && $user->isSuperAdmin()) {
                $user->forceDelete();
            }
        });

        DB::connection('central')->table('user_tenant_lookups')->where('email', $email)->delete();
    }

    /**
     * The account inside the customer's database, made from the record in ours.
     *
     * The password travels as the hash it already is: whoever was handed it
     * keeps it, and nothing here can read it back. Eloquent's 'hashed' cast
     * leaves a value that is already hashed alone, so it is not hashed twice.
     */
    private function copyIntoTheTenant(Tenant $tenant, TenantSuperAdmin $record): void
    {
        Tenancy::within($tenant, function () use ($record) {
            $role = Role::firstOrCreate(['name' => Role::SUPERADMIN]);

            $user = User::withoutGlobalScopes()->where('email', $record->email)->first();

            if ($user) {
                $user->update(['password' => $record->password]);
            } else {
                $user = User::create([
                    'name' => $record->name,
                    'email' => $record->email,
                    'password' => $record->password,
                    'seat_type' => 'office',
                ]);
            }

            $user->roles()->syncWithoutDetaching($role->id);
        });

        /**
         * Logging in looks the address up here first. Creating a user writes
         * this row by itself, but a user that was already there does not, and
         * an import empties this table for the customer before filling it from
         * their database again.
         */
        DB::connection('central')->table('user_tenant_lookups')->updateOrInsert(
            ['email' => $record->email],
            ['tenant_id' => $tenant->id],
        );
    }
}
