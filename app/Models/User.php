<?php

namespace App\Models;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Domain\Signals\Users\UserPlanGroupsChanged;
use App\Domain\Signals\Users\UserRolesChanged;
use App\Models\Scopes\HidesSuperAdmins;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use App\Support\AddressFormatter;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasActivities;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use RecordsHistory {
        recordHistory as private recordOwnHistory;
    }
    use SoftDeletes;

    /** Where someone lives: theirs and the user administrator's to see, nobody else's. */
    public const HOME_ADDRESS = [
        'home_address',
        'home_postal_code',
        'home_city',
    ];

    private ?bool $is_super_admin = null;

    private ?bool $is_admin = null;

    /** @var array<int, string>|null */
    private ?array $permission_names = null;

    /** @var array<int, int>|null */
    private ?array $relevant_asset_ids = null;

    /** Depth of User::withoutHistory(); above zero nothing about a user is recorded. */
    private static int $unrecorded = 0;

    protected array $activity_labels = [
        'name' => 'Naam',
        'email' => 'E-mailadres',
        'plannable' => 'Inplanbaar',
        'seat_type' => 'Soort plek',
        'home_address' => 'Huisadres',
        'home_postal_code' => 'Postcode huisadres',
        'home_city' => 'Woonplaats',
    ];

    /**
     * Bookkeeping of the login itself, and a column from before plan groups were
     * a list. The password is never logged: UserPasswordChanged says that it
     * changed, without it.
     */
    protected array $activity_ignore = [
        'email_verified_at',
        'user_plan_group_id',
    ];

    protected array $activity_formats = [
        'seat_type' => [self::class, 'seatTypeLabel'],
    ];

    /**
     * Whoever may change someone's profile. UserPolicy::seeHomeAddress also lets
     * a person see their own address, but an entry is read under one permission,
     * so the history of an address is for the user administrators only.
     */
    protected array $activity_permissions = [
        'home_address' => 'user.update',
        'home_postal_code' => 'user.update',
        'home_city' => 'user.update',
    ];

    /** MajorLabel's own accounts stay out of sight at the customer. */
    protected static function booted(): void
    {
        static::addGlobalScope(new HidesSuperAdmins);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'plannable',
        /**
         * Field or office staff. Without it here it fell away silently on
         * creation -- the form asked for it, the validation approved it and
         * everyone ended up on the default, which meant the field seats never
         * filled up.
         */
        'seat_type',
        ...self::HOME_ADDRESS,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        /**
         * A user travels along on nearly every page -- planner rows, the people
         * on a work order, the author of a remark. The profile pages make it
         * visible for whoever may see it (UserPolicy::seeHomeAddress).
         */
        ...self::HOME_ADDRESS,
    ];

    /**
     * Attributes that should be appended when the model is serialized.
     *
     * @var list<string>
     */
    protected $appends = [
        'avatar',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'plannable' => 'boolean',
        ];
    }

    /**
     * Accessor for the user's avatar path (or URL).
     */
    public function getAvatarAttribute(): ?string
    {
        if (!$this->id) {
            return null;
        }

        $directory = "users/{$this->id}/avatar";

        if (!Storage::disk('public')->exists($directory)) {
            return null;
        }

        $files = Storage::disk('public')->files($directory);
        if (empty($files)) {
            return null;
        }

        return url("/files/avatars/{$this->id}");
    }

    /** The home address in one line, or null when it is too incomplete to find. */
    public function homeAddress(): ?string
    {
        if (blank($this->home_address) || (blank($this->home_postal_code) && blank($this->home_city))) {
            return null;
        }

        return AddressFormatter::format($this->home_address, $this->home_postal_code, $this->home_city);
    }

    /**
     * Roles assigned to this user.
     */
    public function roles()
    {
        return $this->morphToMany(Role::class, 'roleable', 'roleables')->withTimestamps();
    }

    /**
     * Plan groups this user belongs to.
     */
    public function planGroups()
    {
        return $this->belongsToMany(UserPlanGroup::class, 'plan_group_user')->withTimestamps();
    }

    /**
     * Puts this person in exactly these plan groups and says which came and went.
     *
     * @param  array<int, int|string>  $group_ids
     */
    public function syncPlanGroups(array $group_ids): void
    {
        $names = fn () => $this->planGroups()->pluck('user_plan_groups.name', 'user_plan_groups.id')->all();
        $before = $names();

        $this->planGroups()->sync($group_ids);

        $groups = new NameListChange($before, $names());

        if (!$groups->isEmpty()) {
            Signals::dispatch(new UserPlanGroupsChanged($this, $groups));
        }
    }

    /**
     * Get a flat list of unique permission names for this user
     * combining permissions via roles.
     *
     * Remembered per instance, like isSuperAdmin(): a policy asks this for
     * every appointment in the planner's week. Changing the roles through
     * syncRoles(), or refresh(), forgets it.
     *
     * @return array<int,string>
     */
    public function permissionNames(): array
    {
        return $this->permission_names ??= array_values(array_unique(
            $this->roles()->with('permissions:id,name')->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('name'))
                ->all()
        ));
    }

    /**
     * Whether the user has the admin role.
     *
     * The super admin counts: by definition they may do everything an admin
     * may, and more.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin ??= $this->roles()->whereIn('name', ['admin', Role::SUPERADMIN])->exists();
    }

    /**
     * Forgets the remembered answers about roles and permissions, for code that
     * gives or takes a role and asks again on the same instance.
     */
    public function forgetPermissions(): static
    {
        $this->is_admin = null;
        $this->is_super_admin = null;
        $this->permission_names = null;
        $this->relevant_asset_ids = null;

        return $this;
    }

    public function refresh()
    {
        $this->forgetPermissions();

        return parent::refresh();
    }

    /**
     * The users occupying a seat from the subscription.
     *
     * Our own super admin does not count: that account belongs to MajorLabel
     * and the customer should not pay a seat for it. Soft deleted users already
     * fall outside the default scope.
     */
    public function scopeOccupyingSeat($query, string $seat_type)
    {
        return $query->where('seat_type', $seat_type)->withoutSuperAdmins();
    }

    /** What a seat type is called on the screen and in the trail. */
    public static function seatTypeLabel(?string $seat_type): ?string
    {
        return match ($seat_type) {
            'field' => 'Buitendienst',
            'office' => 'Binnendienst',
            default => $seat_type,
        };
    }

    /**
     * The one place that knows how to keep a super admin out of a query. The
     * global scope, the seat count and everything that comes later go through
     * here, so there is only one definition.
     */
    public function scopeWithoutSuperAdmins($query)
    {
        return $query->whereDoesntHave('roles', fn ($role) => $role->where('name', Role::SUPERADMIN));
    }

    /**
     * The users this person may read, mirroring UserPolicy: everyone but
     * MajorLabel's accounts for whoever may read users, and a super admin
     * everyone, as Gate::before lets them past the policy. Asked of the person
     * given, not of whoever is logged in, which the global scope goes by.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->isSuperAdmin()) {
            return $query->withoutGlobalScope(HidesSuperAdmins::class);
        }

        if ($user?->can('viewAny', self::class)) {
            return $query->withoutSuperAdmins();
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * MajorLabel itself, inside a customer's database.
     *
     * Remembered per instance: the global scope asks this on every query for
     * users, and that is one extra query each time for an answer that does not
     * change within a request. A ->fresh() gives a new instance and therefore a
     * new answer.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin ??= $this->roles()->where('name', Role::SUPERADMIN)->exists();
    }

    /**
     * MajorLabel's own accounts never appear in a customer's trail: they are
     * hidden from the customer everywhere else, and a line about one would give
     * them away. Asked of the database rather than of the remembered answer, so
     * a role given earlier in the same request counts -- but only once there is
     * anything to record: a login that only renews its token asks nothing.
     */
    public function recordHistory(string $action): void
    {
        if (self::$unrecorded > 0) {
            return;
        }

        if ($action === 'updated' && array_filter(array_keys($this->getChanges()), $this->shouldLogField(...)) === []) {
            return;
        }

        if ($action !== 'created' && $this->roles()->where('name', Role::SUPERADMIN)->exists()) {
            return;
        }

        $this->recordOwnHistory($action);
    }

    /**
     * Runs work on users without a line in the trail. For the landlord panel,
     * which writes MajorLabel's account into a customer's database: at the moment
     * that account is created it has no role yet, so nothing else can tell it
     * apart from one of the customer's own.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public static function withoutHistory(callable $work): mixed
    {
        self::$unrecorded++;

        try {
            return $work();
        } finally {
            self::$unrecorded--;
        }
    }

    /**
     * Gives this person exactly these roles and says which came and went. Our
     * own role is never named: the forms that call this refuse it already.
     *
     * @param  array<int, int|string>  $role_ids
     */
    public function syncRoles(array $role_ids): void
    {
        $names = fn () => $this->roles()->assignable()->pluck('roles.name', 'roles.id')->all();
        $before = $names();

        $this->roles()->sync($role_ids);
        $this->forgetPermissions();

        $roles = new NameListChange($before, $names());

        if (!$roles->isEmpty()) {
            Signals::dispatch(new UserRolesChanged($this, $roles));
        }
    }

    /**
     * Whether this permission was actually granted to this person, ignoring the
     * fact that admins are otherwise assumed to have everything.
     *
     * For anything being trialled by a named group, "admin" is the wrong bar:
     * it is a far wider set than "the people testing this", and it grows on its
     * own as people are made admins for unrelated reasons.
     */
    public function hasExplicitPermission(string $name): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }

    public function hasPermission(string $name): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($name, $this->permissionNames(), true);
    }

    /**
     * Every permission from the list, not one of them. An empty list is no
     * barrier: whoever needs no permission, passes.
     *
     * @param  array<int, string>  $names
     */
    public function hasEveryPermission(array $names): bool
    {
        foreach ($names as $name) {
            if (!$this->hasPermission($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One permission from the list is enough. An empty list grants nothing,
     * where hasEveryPermission lets everyone through.
     *
     * @param  array<int, string>  $names
     */
    public function hasAnyPermission(array $names): bool
    {
        foreach ($names as $name) {
            if ($this->hasPermission($name)) {
                return true;
            }
        }

        return false;
    }

    public function scopeCanLeadProjects($query)
    {
        return $query->whereHas('roles', function ($role_query) {
            $role_query->where('name', 'admin')
                ->orWhereHas('permissions', function ($permission_query) {
                    $permission_query->where('name', 'projects.lead');
                });
        });
    }

    /**
     * The machines of the keuringen and storingen on the open werkbonnen this
     * person is planned for. Kept on this instance, like the permissions: the
     * machine scope asks it, and the read layer asks that inside whereHas after
     * whereHas. forgetPermissions() forgets it along with them.
     *
     * @return array<int>
     */
    public function relevantAssetIds(): array
    {
        return $this->relevant_asset_ids ??= $this->findRelevantAssetIds();
    }

    /** @return array<int> */
    private function findRelevantAssetIds(): array
    {
        $serviceorders = ServiceOrder::workedOnBy($this)
            ->with(['serviceJobs:id,service_order_id,asset_id', 'tickets:id,service_order_id,asset_id'])
            ->get();

        $asset_ids = $serviceorders->flatMap(function ($so) {
            $job_assets = $so->serviceJobs->pluck('asset_id');
            $ticket_assets = $so->tickets->pluck('asset_id');

            return $job_assets->merge($ticket_assets);
        })->unique()->values()->all();

        return $asset_ids;
    }

    public function googleCalendarIntegration()
    {
        return $this->hasOne(GoogleCalendarIntegration::class);
    }

    public function calendarGrantsOwned()
    {
        return $this->hasMany(CalendarGrant::class, 'owner_user_id');
    }

    public function calendarGrantsReceived()
    {
        return $this->hasMany(CalendarGrant::class, 'viewer_user_id');
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(UserUnavailability::class);
    }

    public function locationPings()
    {
        return $this->hasMany(LocationPing::class)->orderByDesc('recorded_at');
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function trayItems(): HasMany
    {
        return $this->hasMany(TrayItem::class);
    }

    /**
     * Not notifications(): that name belongs to the Notifiable trait, which reads
     * Laravel's own database channel and knows nothing about these.
     *
     * One action can raise several notifications within the same second, which
     * leaves created_at unable to order them; the id breaks the tie so newest
     * first means it.
     */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function notificationMutes(): HasMany
    {
        return $this->hasMany(NotificationMute::class);
    }
}
