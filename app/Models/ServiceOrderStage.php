<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderStage extends Model
{
    use HasActivities;
    use HasFactory;

    protected $fillable = [
        'name',
        'order',
        'is_planned_state',
        'is_closed_state',
        'is_plannable_state',
        'is_planning_cancelled_state',
        'is_invoiced_state',
        'is_incomplete_state',
    ];

    protected $casts = [
        'is_planned_state' => 'boolean',
        'is_closed_state' => 'boolean',
        'is_plannable_state' => 'boolean',
        'is_planning_cancelled_state' => 'boolean',
        'is_invoiced_state' => 'boolean',
        'is_incomplete_state' => 'boolean',
    ];

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
