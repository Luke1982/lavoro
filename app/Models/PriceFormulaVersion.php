<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved state of a price formula's graph. A version a contract uses is
 * never changed again: saving the formula then makes the next version.
 *
 * graph: {nodes: [{id, type, position: {x, y}, data: {...}}], edges: [{id,
 * source, sourceHandle, target, targetHandle}]} — the editor's own shape.
 * name: what the person called the version, such as the year its prices are
 * for; a blank one is no name.
 */
class PriceFormulaVersion extends Model
{
    protected $fillable = ['price_formula_id', 'number', 'name', 'graph', 'created_by'];

    protected $casts = [
        'graph' => 'array',
        'number' => 'integer',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::cleanName($value));
    }

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

    /** "Airco standaard, versie 3 (2027)", as the timeline and the contract name it. */
    public function displayName(): string
    {
        return ($this->formula?->name ?? 'Prijsformule') . ', ' . $this->label();
    }

    /** "versie 3", or "versie 3 (2027)" when the version was given a name. */
    public function label(): string
    {
        return self::labelOf($this->number, $this->name);
    }

    public static function labelOf(int $number, ?string $name): string
    {
        return 'versie ' . $number . ($name === null ? '' : ' (' . $name . ')');
    }

    public static function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
