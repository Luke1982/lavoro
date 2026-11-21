<?php

namespace App\Models;

use App\Enums\ContractInterval;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A blueprint for a maintenance contract: everything a contract is made of
 * except its customer and its machines. Applying one prefills the contract
 * form; nothing stays linked afterwards, so editing a template never touches
 * contracts that were made with it.
 */
class MaintenanceContractTemplate extends Model
{
    use HasActivities;
    use RecordsHistory;

    public const FINANCIAL_ATTRIBUTES = MaintenanceContract::FINANCIAL_ATTRIBUTES;

    protected array $activity_labels = [
        'name' => 'Naam',
        'title' => 'Contracttitel',
        'duration_months' => 'Looptijd in maanden',
        'price' => 'Prijs',
        'price_interval' => 'Prijsinterval',
        'price_interval_days' => 'Prijsinterval (dagen)',
        'price_formula_id' => 'Prijsformule',
        'manage_frequency_per_asset' => 'Frequentie per machine',
        'frequency' => 'Servicefrequentie',
        'frequency_days' => 'Servicefrequentie (dagen)',
        'auto_generate' => 'Automatisch genereren',
        'auto_generate_interval' => 'Genereerinterval',
        'auto_generate_interval_days' => 'Genereerinterval (dagen)',
    ];

    protected array $activity_relations = [
        'price_formula_id' => ['priceFormula', 'name'],
    ];

    protected array $activity_formats = [
        'price' => [Money::class, 'euros'],
    ];

    /** Gated like the contracts it prices. */
    protected array $activity_permissions = [
        'price' => 'maintenancecontract.see_financials',
        'price_interval' => 'maintenancecontract.see_financials',
        'price_interval_days' => 'maintenancecontract.see_financials',
    ];

    protected $fillable = [
        'name',
        'title',
        'duration_months',
        'price',
        'price_interval',
        'price_interval_days',
        'price_formula_id',
        'manage_frequency_per_asset',
        'frequency',
        'frequency_days',
        'auto_generate',
        'auto_generate_interval',
        'auto_generate_interval_days',
    ];

    /**
     * A template's price is the price its contracts get, so it is hidden like
     * theirs. The template pages and the contract drawer make it visible for
     * whoever may see it.
     */
    protected $hidden = self::FINANCIAL_ATTRIBUTES;

    protected $casts = [
        'duration_months' => 'integer',
        'price' => 'decimal:2',
        'price_interval' => ContractInterval::class,
        'manage_frequency_per_asset' => 'boolean',
        'frequency' => ContractInterval::class,
        'auto_generate' => 'boolean',
        'auto_generate_interval' => ContractInterval::class,
    ];

    /**
     * The price formula a contract made from this template is priced by: the
     * newest version at the moment the contract is made.
     */
    public function priceFormula()
    {
        return $this->belongsTo(PriceFormula::class);
    }

    /**
     * Which templates a price formula prices is news on the formula: a template
     * that takes it, leaves it for another, is made with it or deleted is told
     * on the formula it left as well as on the one it took.
     *
     * What lands there is the whole save, not only the formula: whatever else
     * the save that switched the formula changed comes along (the price in its
     * own entry, which keeps its gate), and so does a later save of the template
     * in the same request that is folded into one of those entries. Any other
     * save that leaves the formula alone stays on the template.
     */
    public function historyTimelines(string $action): array
    {
        if ($action === 'updated' && !$this->wasChanged('price_formula_id')) {
            return [];
        }

        $ids = [
            $this->price_formula_id,
            $action === 'updated' ? ($this->getPrevious()['price_formula_id'] ?? null) : null,
        ];

        return PriceFormula::whereKey(array_filter($ids))->get()->all();
    }

    public function historyLabel(): ?string
    {
        return 'Contractsjabloon ' . $this->name;
    }
}
