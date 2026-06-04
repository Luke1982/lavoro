<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'description',
        'user_id',
    ];

    public function serviceOrders(): MorphToMany
    {
        return $this->morphedByMany(ServiceOrder::class, 'activityable')->withTimestamps();
    }

    public function serviceOrderStages(): MorphToMany
    {
        return $this->morphedByMany(ServiceOrderStage::class, 'activityable')->withTimestamps();
    }

    public function tickets(): MorphToMany
    {
        return $this->morphedByMany(Ticket::class, 'activityable')->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
