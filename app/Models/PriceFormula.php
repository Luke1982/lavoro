<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * A price formula: a node graph that works out what a maintenance contract
 * costs from the machines on it. Its versions are what contracts point at, so
 * changing a formula never reprices a running contract by itself.
 */
class PriceFormula extends Model
{
    use HasActivities;
    use RecordsHistory;

    protected $fillable = ['name', 'description'];

    protected array $activity_labels = [
        'name' => 'Naam',
        'description' => 'Omschrijving',
    ];

    /**
     * A formula that goes is first taken off the templates that chose it, one by
     * one, so each template's history says it lost its formula; the database
     * would otherwise empty the column in silence. Then its own timeline goes.
     */
    protected static function booted(): void
    {
        static::deleting(function (PriceFormula $formula): void {
            $formula->templates()->get()->each->update(['price_formula_id' => null]);

            DB::table('activityables')
                ->where('activityable_type', PriceFormula::class)
                ->where('activityable_id', $formula->id)
                ->delete();
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PriceFormulaVersion::class)->orderByDesc('number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(PriceFormulaVersion::class)->latestOfMany('number');
    }

    public function contracts(): HasManyThrough
    {
        return $this->hasManyThrough(MaintenanceContract::class, PriceFormulaVersion::class);
    }

    /**
     * The contracts on an older version than the newest, which keep their price
     * until somebody moves them. Ended ones included: add notEnded() for the
     * ones still running. The newest version is looked up in the query itself,
     * so it holds right after a save and inside withCount().
     */
    public function contractsBehind(): HasManyThrough
    {
        return $this->contracts()->where('price_formula_versions.number', '<', fn ($query) => $query
            ->from('price_formula_versions as newest')
            ->whereColumn('newest.price_formula_id', 'price_formula_versions.price_formula_id')
            ->selectRaw('max(newest.number)'));
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MaintenanceContractTemplate::class);
    }
}
