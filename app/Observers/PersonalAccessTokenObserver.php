<?php

namespace App\Observers;

use App\Models\Central\AccessTokenTenantLookup;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Keeps track of which tenant belongs to which token.
 *
 * The list only points at the database; whether the token is any good is
 * decided by the tenant database itself. A stray or outdated row therefore
 * yields a 401 at most, never access.
 */
class PersonalAccessTokenObserver
{
    public function created(PersonalAccessToken $token): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        AccessTokenTenantLookup::on('central')->updateOrCreate(
            ['token_hash' => $token->token],
            ['tenant_id' => (string) tenancy()->tenant->getTenantKey()],
        );
    }

    public function deleted(PersonalAccessToken $token): void
    {
        AccessTokenTenantLookup::on('central')->where('token_hash', $token->token)->delete();
    }
}
