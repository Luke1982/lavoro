<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\NodeCatalog;
use App\Models\PriceFormula;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A new price formula with its first version: the graph given, or one price per
 * machine to start from. The screen and the assistant both make them here.
 */
class CreatePriceFormulaAction
{
    public function execute(string $name, ?string $description, ?array $graph, ?User $user): PriceFormula
    {
        return DB::transaction(function () use ($name, $description, $graph, $user) {
            $formula = PriceFormula::create(['name' => $name, 'description' => $description]);
            $formula->versions()->create([
                'number' => 1,
                'graph' => $graph ?? NodeCatalog::starterGraph(),
                'created_by' => $user?->id,
            ]);

            return $formula;
        });
    }
}
