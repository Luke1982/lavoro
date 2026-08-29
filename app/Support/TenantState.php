<?php

namespace App\Support;

final class TenantState
{
    public static function flush(): void
    {
        foreach (app()->tagged(ForgetsTenantState::class) as $state) {
            $state->forgetTenantState();
        }
    }
}
