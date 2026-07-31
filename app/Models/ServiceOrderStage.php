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

    /**
     * Facturatie volgt op sluiten, dus een gefactureerde fase sluit de werkbon net
     * zo goed als de gesloten fase zelf. Iedere lezer stelt de vraag hier, zodat
     * niemand per ongeluk alleen naar is_closed_state kijkt.
     */
    public function closesOrder(): bool
    {
        return $this->is_closed_state === true || $this->is_invoiced_state === true;
    }

    /**
     * Dezelfde vraag als closesOrder(), maar voor een query: beperkt tot de fases
     * die een werkbon als gesloten laten gelden.
     */
    public function scopeClosesOrder($query)
    {
        return $query->where(fn ($q) => $q
            ->where('is_closed_state', true)
            ->orWhere('is_invoiced_state', true));
    }
}
