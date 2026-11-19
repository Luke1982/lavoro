<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Remark;
use App\Models\User;

class RemarkPolicy
{
    /**
     * Only whoever wrote a remark takes it back, and on an appointment only while
     * they may still give feedback on it. A remark from a customer stays.
     */
    public function delete(User $user, Remark $remark): bool
    {
        if (!$remark->user()->is($user)) {
            return false;
        }

        $record = $remark->remarkable();

        return !$record instanceof Event || $user->can('provideFeedback', $record);
    }
}
