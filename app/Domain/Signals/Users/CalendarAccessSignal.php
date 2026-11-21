<?php

namespace App\Domain\Signals\Users;

use App\Domain\Signals\PersonName;
use App\Models\CalendarGrant;
use App\Models\User;

/**
 * Someone's appointments shown in, or taken out of, another person's Google
 * Calendar. Recorded on the person whose calendar it is, and on the one who may
 * look.
 *
 * Both people are read past every scope: either may have left since, and in the
 * customer's request MajorLabel's accounts do not resolve. A grant naming one of
 * them must still be recorded and must still reach the listeners that keep
 * Google in step. MajorLabel's accounts are never named: UserSignal stays silent
 * about an owner of theirs, and a viewer of theirs gets no line either.
 */
abstract class CalendarAccessSignal extends UserSignal
{
    public ?User $viewer;

    public function __construct(public CalendarGrant $grant)
    {
        $this->viewer = User::withoutGlobalScopes()->find($grant->viewer_user_id);

        parent::__construct(User::withoutGlobalScopes()->findOrFail($grant->owner_user_id));
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    /** @return array<int, User> */
    public function activityContext(): array
    {
        return $this->viewerMayBeNamed() ? [$this->viewer] : [];
    }

    protected function viewerMayBeNamed(): bool
    {
        return $this->viewer !== null && !$this->viewer->isSuperAdmin();
    }

    protected function ownerName(): ?string
    {
        return PersonName::of($this->grant->owner_user_id);
    }

    protected function viewerName(): ?string
    {
        return PersonName::of($this->grant->viewer_user_id);
    }
}
