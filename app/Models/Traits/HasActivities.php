<?php

namespace App\Models\Traits;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @mixin Model
 */
trait HasActivities
{
    public function activities(): MorphToMany
    {
        return $this->morphToMany(Activity::class, 'activityable')->withTimestamps();
    }
}
