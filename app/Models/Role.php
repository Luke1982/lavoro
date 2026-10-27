<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

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

    /** Roles a customer may see and use: everything except ours. */
    public function scopeAssignable($query)
    {
        return $query->where('name', '!=', self::SUPERADMIN);
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
}
