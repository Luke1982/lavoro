<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
        return Storage::url($files[0]);
    }

    /**
     * Roles assigned to this user.
     */
    public function roles()
    {
        return $this->morphToMany(Role::class, 'roleable', 'roleables')->withTimestamps();
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
     */
    public function isAdmin(): bool
    {
        return $this->roles()->where('name', 'admin')->exists();
    }

    public function hasPermission(string $name): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return in_array($name, $this->permissionNames(), true);
    }

    /**
     * Get asset IDs relevant for open serviceorders where user is executing.
     *
     * @return array<int>
     */
    public function relevantAssetIds(): array
    {
        $serviceorders = $this->serviceOrdersExecuting()->where('status', '!=', 'closed')->get();
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
}
