<?php

namespace App\Models\Traits;

use App\Models\Remark;

trait RemarkableTrait
{
    public function remarks()
    {
        return $this->morphToMany(Remark::class, 'remarkable')
            ->withPivot('internal')
            ->wherePivot('internal', false)
            ->orderBy('created_at', 'desc')
            ->withTimestamps();
    }

    public function internalRemarks()
    {
        return $this->morphToMany(Remark::class, 'remarkable')
            ->withPivot('internal')
            ->wherePivot('internal', true)
            ->orderBy('created_at', 'desc')
            ->withTimestamps();
    }
}
