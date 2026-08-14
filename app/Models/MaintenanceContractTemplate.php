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
    protected $fillable = [
        'name',
        'title',
        'duration_months',
        'price',
        'price_interval',
        'price_interval_days',
        'manage_frequency_per_asset',
        'frequency',
        'frequency_days',
        'auto_generate',
        'auto_generate_interval',
        'auto_generate_interval_days',
    ];

    protected $casts = [
        'duration_months' => 'integer',
        'price' => 'decimal:2',
        'price_interval' => ContractInterval::class,
        'manage_frequency_per_asset' => 'boolean',
        'frequency' => ContractInterval::class,
        'auto_generate' => 'boolean',
        'auto_generate_interval' => ContractInterval::class,
    ];
}
