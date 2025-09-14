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
}
