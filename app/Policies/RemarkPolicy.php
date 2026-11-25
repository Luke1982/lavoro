<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Remark;
use App\Models\User;

class RemarkPolicy
{
    /** Remarks need no right of their own: they are read on whatever they are placed on. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** This one remark: on a record this person may read (Remark::scopeVisibleTo). */
    public function view(User $user, Remark $remark): bool
    {
        return Remark::visibleTo($user)->whereKey($remark->id)->exists();
    }

    /**
     * Only whoever wrote a remark takes it back, and on an appointment only while
     * they may still give feedback on it, and on a keurpunt only while they may
     * still fill in its keuring. A remark from a customer stays.
     */
    public function delete(User $user, Remark $remark): bool
    {
        if (!$remark->user()->is($user)) {
            return false;
        }

        $record = $remark->attachedRecord();

        return (!$record instanceof Event || $user->can('provideFeedback', $record))
            && $remark->keurpuntenAllowChangesBy($user);
    }
}
