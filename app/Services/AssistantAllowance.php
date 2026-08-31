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

    /** Het maandtegoed. Begint elke maand opnieuw. */
    public function monthlyMicros(): int
    {
        return (int) (tenancy()->tenant->ai_allowance_micros
            ?? PricingSetting::value('ai_allowance_micros', 22_500_000));
    }

    /**
     * Wat er ooit is bijgekocht, min wat er in eerdere maanden overheen ging.
     * Bijkopen verloopt niet, dus het telt over de hele looptijd.
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
     * Bijgekocht tegoed gaat er pas aan zodra het maandtegoed op is, en wat
     * daarvan over is blijft staan voor de volgende maand.
     *
     * Er wordt per maand gekeken naar wat er bóven het maandtegoed uit ging, en
     * niet naar het totaal. Anders eet een klant die drie maanden netjes onder
     * zijn tegoed bleef zijn bijkoop op zonder hem ooit gebruikt te hebben.
     *
     * Het maandtegoed van nu geldt daarbij ook voor eerdere maanden. Wat het
     * toen precies was is niet vastgelegd, en dat achteraf reconstrueren is
     * meer werk dan het verschil waard is.
     */
    public function topupRemainingMicros(): int
    {
        $monthly = $this->monthlyMicros();

        /** In PHP gegroepeerd en niet in SQL: de tests draaien op sqlite. */
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
