<?php

namespace App\Support;

final class MailerState implements ForgetsTenantState
{
    public function forgetTenantState(): void
    {
        app('mail.manager')->forgetMailers();
    }
}
