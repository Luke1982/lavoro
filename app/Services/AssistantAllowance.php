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

    public function allowanceMicros(): int
    {
        return (int) (tenancy()->tenant->ai_allowance_micros
            ?? PricingSetting::value('ai_allowance_micros', 12_500_000));
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
