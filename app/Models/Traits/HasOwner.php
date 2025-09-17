<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Auth;

trait HasOwner
{
    public static function bootHasOwner(): void
    {
        static::created(function ($model) {
            if (Auth::check()) {
                $user_id = Auth::id();
                $exists = $model->owners()->where('users.id', $user_id)->wherePivot('type', 'owner')->exists();
                if (!$exists) {
                    $model->owners()->attach($user_id, ['type' => 'owner']);
                }
            }
        });
    }

    public function owners(): MorphToMany
    {
        return $this->morphToMany(User::class, 'userable')->withPivot('type')->withTimestamps();
    }

    public function owner()
    {
        return $this->owners()->wherePivot('type', 'owner')->first();
    }
}
