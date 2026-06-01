<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderStage extends Model
{
    use HasFactory;
    use HasActivities;

    protected $fillable = [
        'name',
        'order',
        'is_planned_state',
        'is_closed_state',
        'is_plannable_state',
    ];

    protected $casts = [
        'is_planned_state'   => 'boolean',
        'is_closed_state'    => 'boolean',
        'is_plannable_state' => 'boolean',
    ];

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
