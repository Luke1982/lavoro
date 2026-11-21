<?php

namespace App\Domain\Signals\Users;

use App\Domain\Signals\BaseSignal;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * A role was given permissions or had them taken away. Everyone in the role
 * gains or loses them at once, which is why this is worth a line of its own:
 * "since when may monteurs see prices" is answered here and nowhere else.
 */
class RolePermissionsChanged extends BaseSignal
{
    public function __construct(public Role $role, public NameListChange $permissions)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'role.permissions_changed';
    }

    public static function label(): string
    {
        return 'Rechten van rol gewijzigd';
    }

    public function subject(): Model
    {
        return $this->role;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        return 'Rechten van rol ' . $this->role->name . ': ' . $this->permissions->sentence();
    }

    public function changes(): array
    {
        return $this->permissions->deltaChanges('permissions', 'Rechten erbij', 'Rechten eraf');
    }

    public function requiredPermission(): ?string
    {
        return 'user.assign_roles';
    }
}
