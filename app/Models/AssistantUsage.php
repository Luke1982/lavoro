<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one call to the model cost. See the migration for why the four token
 * counts and the rates are all kept.
 *
 * @mixin Model
 */
class AssistantUsage extends Model
{
    /**
     * Central and not in the customer's database. The allowance is measured off
     * here and management adds it up across all customers; that cannot come
     * from a table that sits separately per customer. The customer does not
     * reach it.
     */
    protected $connection = 'central';

    protected $table = 'assistant_usage';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_write_tokens',
        'cache_read_tokens',
        'cost_micros',
        'cost_usd_micros',
        'eur_per_usd',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cost_micros' => 'integer',
        'cost_usd_micros' => 'integer',
        'eur_per_usd' => 'float',
    ];

    protected static function booted(): void
    {
        /**
         * Adding the customer and filtering on it as well, so that no count
         * ever accidentally covers another company's usage.
         */
        static::creating(function (self $usage) {
            $usage->tenant_id ??= static::tenantKey();
        });

        static::addGlobalScope('tenant', function (Builder $query) {
            $query->where('assistant_usage.tenant_id', static::tenantKey());
        });
    }

    public static function tenantKey(): string
    {
        return (string) (tenancy()->initialized ? tenancy()->tenant->getTenantKey() : '');
    }

    /** The user lives in the customer's database, the usage centrally. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Spend so far in the month a date falls in. This is the number the
     * allowance is measured against once tenancy lands, so it counts everyone:
     * a cap is on the company, not on a person.
     */
    public static function spentMicrosInMonth(?\DateTimeInterface $when = null): int
    {
        $moment = $when ? CarbonImmutable::instance($when) : CarbonImmutable::now();

        return (int) static::query()
            ->whereBetween('created_at', [$moment->startOfMonth(), $moment->endOfMonth()])
            ->sum('cost_micros');
    }

    public function scopeInMonth(Builder $query, \DateTimeInterface $when): Builder
    {
        $moment = CarbonImmutable::instance($when);

        return $query->whereBetween('created_at', [$moment->startOfMonth(), $moment->endOfMonth()]);
    }
}
