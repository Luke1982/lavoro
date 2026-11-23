<?php

namespace App\Actions\Pricing;

use App\Domain\Signals\Pricing\PriceFormulaVersionRenamed;
use App\Domain\Signals\Signals;
use App\Models\PriceFormulaVersion;
use Illuminate\Support\Facades\DB;

/** Gives a version of a price formula a name, or takes it away. The prices on it stay as they are. */
class RenamePriceFormulaVersionAction
{
    public function execute(PriceFormulaVersion $version, ?string $name): void
    {
        $previous = $version->name;
        $version->name = $name;

        if (!$version->isDirty('name')) {
            return;
        }

        DB::transaction(function () use ($version, $previous) {
            $version->save();

            Signals::dispatch(new PriceFormulaVersionRenamed($version->formula, $version->number, $previous, $version->name));
        });
    }
}
