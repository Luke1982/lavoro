<?php

namespace App\Actions\ServiceOrders;

use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroSteps;
use App\Domain\Signals\ServiceOrders\ServiceOrderMacroRan;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a macro on a werkbon: the steps on the path its answers choose, in order,
 * all or none. A step that fails undoes the steps before it, and any file a step
 * already stored is taken off the disk again.
 *
 * The run window on the werkbon and the assistant both run macros here, so a
 * macro does the same thing whoever starts it.
 */
class RunServiceOrderMacroAction
{
    /**
     * Why this macro cannot run on this werkbon at all, whatever the answers.
     *
     * A macro is checked when it is saved, so what fails here is what changed
     * since: a task, product, check or category it names was deleted.
     */
    public function refusal(ServiceOrder $order, ServiceOrderMacro $macro): ?string
    {
        return match (true) {
            $order->is_closed => 'De werkbon is gesloten.',
            $macro->steps()->errors() !== [] => 'Macro "' . $macro->title
                . '" verwijst naar iets dat niet meer bestaat; pas de macro aan.',
            default => null,
        };
    }

    public function execute(ServiceOrder $order, ServiceOrderMacro $macro, MacroRun $run): void
    {
        $refusal = $this->refusal($order, $macro);

        throw_if($refusal !== null, Refusal::class, $refusal);

        $steps = $macro->steps()->path($run->answers());

        DB::transaction(function () use ($order, $macro, $run, $steps) {
            /**
             * Inside the transaction, so the files go only when the rows do: what
             * runs after the commit can no longer take away a document that stays.
             */
            try {
                foreach ($steps as $step) {
                    MacroSteps::find($step['type'])->run($order, $step, $run);
                }

                $answered = $macro->steps()->answered($run->answers());
                Signals::dispatch(new ServiceOrderMacroRan($order, $macro->title, $answered));
            } catch (Throwable $failure) {
                Storage::disk('public')->delete($run->storedFiles());

                throw $failure;
            }
        });
    }
}
