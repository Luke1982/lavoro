<?php

namespace App\Models\Traits;

use App\Models\Remark;

trait RemarkableTrait
{
    public function remarks()
    {
        return $this->morphToMany(Remark::class, 'remarkable')
            ->orderBy('created_at', 'desc')
            ->withTimestamps();
    }
}
