<?php

namespace App\Domain\Signals\Users;

use App\Models\User;
use App\Models\UserPlanGroup;

/** Someone was put in plan groups or taken out of them: the rows they appear in in the planner. */
class UserPlanGroupsChanged extends UserSignal
{
    public function __construct(User $user, public NameListChange $groups)
    {
        parent::__construct($user);
    }

    public static function key(): string
    {
        return 'user.plan_groups_changed';
    }

    public static function label(): string
    {
        return 'Plangroepen gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    protected function describe(): ?string
    {
        return 'Plangroepen van ' . $this->user->name . ': ' . $this->groups->sentence();
    }

    protected function describeChanges(): array
    {
        return [$this->groups->change('plan_groups', 'Plangroepen')];
    }

    /** @return array<int, UserPlanGroup> */
    public function activityContext(): array
    {
        $ids = [...$this->groups->addedIds(), ...$this->groups->removedIds()];

        return $ids === [] ? [] : UserPlanGroup::whereKey($ids)->get()->all();
    }
}
