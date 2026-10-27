<?php

namespace App\Observers;

use App\Models\Central\UserTenantLookup;
use App\Models\User;
use RuntimeException;

class UserObserver
{
    public function creating(User $user): void
    {
        if (! $tenant_id = $this->tenantId()) {
            return;
        }

        $existing = UserTenantLookup::on('central')->find($user->email);

        if ($existing && $existing->tenant_id !== $tenant_id) {
            throw new RuntimeException("E-mailadres {$user->email} is al in gebruik bij een andere tenant.");
        }
    }

    public function created(User $user): void
    {
        if ($tenant_id = $this->tenantId()) {
            UserTenantLookup::on('central')->updateOrCreate(['email' => $user->email], ['tenant_id' => $tenant_id]);
        }
    }

    public function updated(User $user): void
    {
        if (! $user->isDirty('email') || ! $tenant_id = $this->tenantId()) {
            return;
        }

        UserTenantLookup::on('central')->where('email', $user->getOriginal('email'))->delete();
        UserTenantLookup::on('central')->updateOrCreate(['email' => $user->email], ['tenant_id' => $tenant_id]);
    }

    public function restored(User $user): void
    {
        if ($tenant_id = $this->tenantId()) {
            UserTenantLookup::on('central')->updateOrCreate(['email' => $user->email], ['tenant_id' => $tenant_id]);
        }
    }

    public function forceDeleted(User $user): void
    {
        UserTenantLookup::on('central')->where('email', $user->email)->delete();
    }

    private function tenantId(): ?string
    {
        return tenancy()->initialized ? (string) tenancy()->tenant->getTenantKey() : null;
    }
}
