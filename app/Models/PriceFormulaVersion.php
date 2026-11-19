<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved state of a price formula's graph. A version a contract uses is
 * never changed again: saving the formula then makes the next version.
 *
 * graph: {nodes: [{id, type, position: {x, y}, data: {...}}], edges: [{id,
 * source, sourceHandle, target, targetHandle}]} — the editor's own shape.
 */
class PriceFormulaVersion extends Model
{
    protected $fillable = ['price_formula_id', 'number', 'graph', 'created_by'];

    protected $casts = [
        'graph' => 'array',
        'number' => 'integer',
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(PriceFormula::class, 'price_formula_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MaintenanceContract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Airco standaard, versie 3", as the timeline and the contract name it. */
    public function displayName(): string
    {
        return ($this->formula?->name ?? 'Prijsformule') . ', versie ' . $this->number;
    }
}
