<?php

namespace App\Services;

use App\Domain\Assistant\AllowanceGate;
use App\Domain\Assistant\UsageCost;
use App\Models\Central\PricingSetting;
use Illuminate\Support\Facades\DB;

class AssistantAllowance implements AllowanceGate
{
    public function spentMicros(): int
    {
        return (int) DB::connection('central')->table('assistant_usage')
            ->where('tenant_id', (string) tenancy()->tenant->getTenantKey())
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');
    }

    /** The monthly allowance. Starts over every month. */
    public function monthlyMicros(): int
    {
        return (int) (tenancy()->tenant->ai_allowance_micros
            ?? PricingSetting::value('ai_allowance_micros', 22_500_000));
    }

    /**
     * What was ever topped up, minus what went over it in earlier months.
     * Topping up does not expire, so it counts over the whole lifetime.
     */
    public function topupMicros(): int
    {
        return (int) DB::connection('central')->table('ai_topups')
            ->where('tenant_id', (string) tenancy()->tenant->getTenantKey())
            ->sum('granted_micros');
    }

    public function allowanceMicros(): int
    {
        return $this->monthlyMicros() + $this->topupRemainingMicros();
    }

    /**
     * Topped up credit is only eaten into once the monthly allowance is gone,
     * and what is left of it stays for the next month.
     *
     * It looks per month at what went above the monthly allowance, and not at
     * the total. Otherwise a customer who stayed neatly under their allowance
     * for three months eats their top-up without ever having used it.
     *
     * Today's monthly allowance also counts for earlier months. What it was
     * back then exactly is not recorded, and reconstructing that afterwards is
     * more work than the difference is worth.
     */
    public function topupRemainingMicros(): int
    {
        $monthly = $this->monthlyMicros();

        /** Grouped in PHP and not in SQL: the tests run on sqlite. */
        $per_month = DB::connection('central')->table('assistant_usage')
            ->where('tenant_id', (string) tenancy()->tenant->getTenantKey())
            ->where('created_at', '<', now()->startOfMonth())
            ->get(['created_at', 'cost_micros'])
            ->groupBy(fn ($row) => substr((string) $row->created_at, 0, 7))
            ->map(fn ($rows) => (int) $rows->sum('cost_micros'));

        $over_the_top = $per_month->sum(fn (int $spent) => max(0, $spent - $monthly));

        return max(0, $this->topupMicros() - (int) $over_the_top);
    }

    public function remainingMicros(): int
    {
        return max(0, $this->allowanceMicros() - $this->spentMicros());
    }

    public function hasRoom(): bool
    {
        return $this->spentMicros() < $this->allowanceMicros();
    }

    public function record(UsageCost $cost, int $user_id): void
    {
        DB::connection('central')->table('assistant_usage')->insert([
            'tenant_id' => (string) tenancy()->tenant->getTenantKey(),
            'user_id' => $user_id,
            'model' => $cost->model,
            'input_tokens' => $cost->input_tokens,
            'output_tokens' => $cost->output_tokens,
            'cache_write_tokens' => $cost->cache_write_tokens,
            'cache_read_tokens' => $cost->cache_read_tokens,
            'cost_micros' => (int) round($cost->euros() * 1_000_000),
            'cost_usd_micros' => 0,
            'eur_per_usd' => $cost->eur_per_usd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
