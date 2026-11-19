<?php

namespace App\Models;

use App\Enums\ContractInterval;
use Illuminate\Database\Eloquent\Model;

/**
 * A blueprint for a maintenance contract: everything a contract is made of
 * except its customer and its machines. Applying one prefills the contract
 * form; nothing stays linked afterwards, so editing a template never touches
 * contracts that were made with it.
 */
class MaintenanceContractTemplate extends Model
{
    public const FINANCIAL_ATTRIBUTES = MaintenanceContract::FINANCIAL_ATTRIBUTES;

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
}
