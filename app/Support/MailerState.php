<?php

namespace App\Support;

use Mockery\MockInterface;

final class MailerState implements ForgetsTenantState
{
    public function forgetTenantState(): void
    {
        $manager = app('mail.manager');

        /**
         * In een test die Mail::shouldReceive gebruikt is de manager een
         * Mockery-dubbelganger, en die klapt op elke aanroep waar de test niet
         * om vroeg. Er valt daar ook niets te vergeten: het ding heeft geen
         * echte mailers. Overslaan dus, in plaats van elke mail-mockende test
         * een verwachting voor deze huishouding te laten opgeven.
         */
        if ($manager instanceof MockInterface) {
            return;
        }

        $manager->forgetMailers();
    }
}
