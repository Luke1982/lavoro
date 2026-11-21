<?php

namespace App\Domain\Signals\Users;

use App\Models\Role;
use App\Models\User;

/**
 * Someone was given roles or had them taken away. A role hands out every
 * permission it holds, so this is the line that answers "since when may Jan
 * close werkbonnen". It also goes on the roles themselves, where it reads as
 * who came into the role and who left it.
 */
class UserRolesChanged extends UserSignal
{
    public function __construct(User $user, public NameListChange $roles)
    {
        parent::__construct($user);
    }

    public static function key(): string
    {
        return 'user.roles_changed';
    }

    public static function label(): string
    {
        return 'Rollen gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    protected function describe(): ?string
    {
        return 'Rollen van ' . $this->user->name . ': ' . $this->roles->sentence();
    }

    protected function describeChanges(): array
    {
        return [$this->roles->change('roles', 'Rollen')];
    }

    /** @return array<int, Role> */
    public function activityContext(): array
    {
        $ids = [...$this->roles->addedIds(), ...$this->roles->removedIds()];

        return $ids === [] ? [] : Role::assignable()->whereKey($ids)->get()->all();
    }

    /** The same fact as RoleMembersChanged, so the same gate: that of whoever hands roles out. */
    public function requiredPermission(): ?string
    {
        return 'user.assign_roles';
    }
}
