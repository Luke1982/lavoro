<?php

namespace App\Models;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Domain\Signals\Users\RoleMembersChanged;
use App\Domain\Signals\Users\RolePermissionsChanged;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasActivities;
    use HasFactory;
    use RecordsHistory {
        recordHistory as private recordOwnHistory;
    }

    /**
     * MajorLabel's own role inside a customer's database. May do everything,
     * everywhere, past every policy.
     *
     * Only creatable from the admin panel. A customer cannot choose it, create
     * it, rename it or grant it -- otherwise it is not a separation but a
     * suggestion.
     */
    public const SUPERADMIN = 'superadmin';

    protected $fillable = [
        'name',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
    ];

    /** Roles a customer may see and use: everything except ours. */
    public function scopeAssignable($query)
    {
        return $query->where('name', '!=', self::SUPERADMIN);
    }

    /**
     * Our own role never appears in a customer's trail, by the name it has now or
     * the one it had: a line about it would name what the customer is not meant
     * to know exists.
     */
    public function recordHistory(string $action): void
    {
        if (in_array(self::SUPERADMIN, [$this->name, $this->getRawOriginal('name')], true)) {
            return;
        }

        $this->recordOwnHistory($action);
    }

    /**
     * Users that have this role.
     */
    public function users()
    {
        return $this->morphedByMany(User::class, 'roleable', 'roleables')->withTimestamps();
    }

    /**
     * Permissions attached to this role.
     */
    public function permissions()
    {
        return $this->morphToMany(Permission::class, 'permissionable', 'permissionables')->withTimestamps();
    }

    /**
     * Puts exactly these people in the role and says who came and who left.
     * MajorLabel's accounts are neither named nor counted, also when one of them
     * is the person at the screen and would otherwise see them.
     *
     * @param  array<int, int|string>  $user_ids
     */
    public function syncUsers(array $user_ids): void
    {
        $names = fn () => $this->users()->withTrashed()->withoutSuperAdmins()->pluck('users.name', 'users.id')->all();
        $before = $names();

        $this->users()->sync($user_ids);

        $members = new NameListChange($before, $names());

        if (!$members->isEmpty()) {
            Signals::dispatch(new RoleMembersChanged($this, $members));
        }
    }

    /**
     * Gives the role exactly these permissions and says which came and went, by
     * the label a person reads on the roles screen.
     *
     * @param  array<int, int|string>  $permission_ids
     */
    public function syncPermissions(array $permission_ids): void
    {
        $labels = fn () => $this->permissions()->get(['permissions.id', 'permissions.name', 'permissions.label'])
            ->mapWithKeys(fn (Permission $permission) => [$permission->id => $permission->label ?: $permission->name])
            ->all();
        $before = $labels();

        $this->permissions()->sync($permission_ids);

        $permissions = new NameListChange($before, $labels());

        if (!$permissions->isEmpty()) {
            Signals::dispatch(new RolePermissionsChanged($this, $permissions));
        }
    }
}
