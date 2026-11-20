<?php

namespace App\Models;

use App\Models\Traits\KeepsPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Why a quote was lost, such as "te duur". A customer who declines on their own
 * page chooses from the reasons they may pick; a colleague marking a quote as
 * declined chooses from all of them.
 */
class QuoteLostReason extends Model
{
    use KeepsPosition;

    protected $fillable = ['name', 'customer_can_pick', 'position'];

    protected function casts(): array
    {
        return ['customer_can_pick' => 'boolean'];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'lost_reason_id');
    }
}
