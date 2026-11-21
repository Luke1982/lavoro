<?php

namespace App\Domain\Signals\Users;

use App\Domain\Signals\BaseSignal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * People were put in a role or taken out of it on the roles screen. Also goes
 * on each of those people, where "Planner: Jan toegevoegd" answers since when
 * someone has the rights that role hands out.
 */
class RoleMembersChanged extends BaseSignal
{
    public function __construct(public Role $role, public NameListChange $members)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'role.members_changed';
    }

    public static function label(): string
    {
        return 'Leden van rol gewijzigd';
    }

    public function subject(): Model
    {
        return $this->role;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return 'Rol ' . $this->role->name . ': ' . $this->members->sentence();
    }

    public function changes(): array
    {
        return $this->members->deltaChanges('users', 'Leden erbij', 'Leden eraf');
    }

    /** @return array<int, User> */
    public function activityContext(): array
    {
        $ids = [...$this->members->addedIds(), ...$this->members->removedIds()];

        return $ids === [] ? [] : User::withTrashed()->whereKey($ids)->get()->all();
    }

    /** Who holds which role is the business of whoever hands roles out. */
    public function requiredPermission(): ?string
    {
        return 'user.assign_roles';
    }
}
