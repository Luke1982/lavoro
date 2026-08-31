<?php

namespace App\Observers;

use App\Models\Central\AccessTokenTenantLookup;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Houdt bij welke tenant bij welk token hoort.
 *
 * De lijst wijst alleen de database aan; of het token deugt beslist de
 * tenantdatabase zelf. Een verdwaalde of verouderde rij levert daarom hooguit
 * een 401 op, nooit toegang.
 */
class PersonalAccessTokenObserver
{
    public function created(PersonalAccessToken $token): void
    {
        if (! tenancy()->initialized) {
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
