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

    protected static function booted(): void
    {
        static::deleting(fn (PriceFormula $formula) => DB::table('activityables')
            ->where('activityable_type', PriceFormula::class)
            ->where('activityable_id', $formula->id)
            ->delete());
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

    public function templates(): HasMany
    {
        return $this->hasMany(MaintenanceContractTemplate::class);
    }
}
