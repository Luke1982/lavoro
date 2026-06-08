<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPlanGroup extends Model
{
    protected $fillable = ['name', 'color', 'sort_order'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'plan_group_user')->withTimestamps();
    }
}
