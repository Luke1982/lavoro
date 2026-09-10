<?php

namespace App\Support;

use Mockery\MockInterface;

final class MailerState implements ForgetsTenantState
{
    public function forgetTenantState(): void
    {
        $manager = app('mail.manager');

        /**
         * In a test using Mail::shouldReceive the manager is a Mockery double,
         * and that blows up on every call the test did not ask for. There is
         * nothing to forget there either: the thing has no real mailers. So
         * skip it, rather than making every mail-mocking test declare an
         * expectation for this housekeeping.
         */
        if ($manager instanceof MockInterface) {
            return;
        }

        $manager->forgetMailers();
    }
}
