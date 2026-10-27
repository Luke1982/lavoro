<?php

namespace App\Models;

use App\Models\Scopes\HidesSuperAdmins;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    private ?bool $is_super_admin = null;

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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
     * Get a flat list of unique permission names for this user
     * combining permissions via roles.
     *
     * @return array<int,string>
     */
    public function permissionNames(): array
    {
        $via_roles = $this->roles()->with('permissions:id,name')->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->all();

        return array_values(array_unique($via_roles));
    }

    /**
     * Whether the user has the admin role.
     *
     * The super admin counts: by definition they may do everything an admin
     * may, and more.
     */
    public function isAdmin(): bool
    {
        return $this->roles()->whereIn('name', ['admin', Role::SUPERADMIN])->exists();
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
     * Get asset IDs relevant for open serviceorders where user is executing.
     *
     * @return array<int>
     */
    public function relevantAssetIds(): array
    {
        $serviceorders = $this->serviceOrdersExecuting()
            ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->closesOrder())
            ->with(['serviceJobs:id,service_order_id,asset_id', 'tickets:id,service_order_id,asset_id'])
            ->get();

        $asset_ids = $serviceorders->flatMap(function ($so) {
            $job_assets = $so->serviceJobs->pluck('asset_id');
            $ticket_assets = $so->tickets->pluck('asset_id');

            return $job_assets->merge($ticket_assets);
        })->unique()->values()->all();

        return $asset_ids;
    }

    /**
     * Get product IDs relevant for open serviceorders where user is executing.
     *
     * @return array<int>
     */
    public function relevantProductIds(): array
    {
        $asset_ids = $this->relevantAssetIds();

        return Asset::whereIn('id', $asset_ids)->pluck('product_id')->unique()->values()->all();
    }

    /**
     * ServiceOrders where user is executing.
     */
    public function serviceOrdersExecuting()
    {
        return ServiceOrder::whereHas('executingUsers', function ($q) {
            $q->where('users.id', $this->id);
        });
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

    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()->pluck('token')->toArray();
    }
}
