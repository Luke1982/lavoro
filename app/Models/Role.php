<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

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
