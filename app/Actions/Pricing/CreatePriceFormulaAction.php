<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Pricing\NodeCatalog;
use App\Domain\Signals\Pricing\PriceFormulaSaved;
use App\Domain\Signals\Signals;
use App\Models\PriceFormula;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A new price formula with its first version: the graph given, or one price per
 * machine to start from. The screen and the assistant both make them here.
 *
 * The first version's blocks are told like every later save, so "what did this
 * formula start out as" has an answer on its timeline.
 */
class CreatePriceFormulaAction
{
    public function __construct(private readonly CompactFormula $compact) {}

    public function execute(string $name, ?string $description, ?array $graph, ?User $user): PriceFormula
    {
        return DB::transaction(function () use ($name, $description, $graph, $user) {
            $formula = PriceFormula::create(['name' => $name, 'description' => $description]);
            $version = $formula->versions()->create([
                'number' => 1,
                'graph' => $graph ?? NodeCatalog::starterGraph(),
                'created_by' => $user?->id,
            ]);

            Signals::dispatch(new PriceFormulaSaved(
                $formula,
                $version->number,
                true,
                null,
                $this->compact->summary($version->graph),
            ));

            return $formula;
        });
    }
}
