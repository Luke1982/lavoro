<?php

namespace App\Actions\Quotes;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Domain\Signals\Quotes\QuoteConvertedToServiceOrder;
use App\Domain\Signals\Signals;
use App\Enums\QuoteLineKinds;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\ServiceOrder;
use App\Services\MateriableService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns an accepted quote into the werkbon that carries it out, once.
 *
 * What was taken goes over; an option the customer left out does not, nor an
 * alternative they did not pick, and a text has nothing to do. Where a line
 * goes ("woonkamer") goes along in its name, so the monteur knows which unit
 * belongs where. A product becomes a task with that product and
 * quantity, so the monteur registers the machines on completion, and the
 * materials that came along with it are booked under that task. Other
 * materials go on the werkbon as planned, and a line of its own becomes a free
 * line. Prices stay on the quote: a werkbon line does not carry one.
 *
 * Everything goes through the paths a werkbon is always filled through, so the
 * stock moves and the werkbon's timeline tells it as usual.
 */
class ConvertQuoteToServiceOrderAction
{
    public function __construct(
        private readonly CreateServiceOrderAction $create_order,
        private readonly CreateTaskInstanceAction $create_task,
        private readonly MateriableService $materials,
    ) {}

    public function execute(Quote $quote): ServiceOrder
    {
        if ($quote->statusEnum() !== QuoteStatuses::geaccepteerd) {
            throw new Refusal('Alleen van een geaccepteerde offerte wordt een werkbon gemaakt.');
        }

        if ($quote->service_order_id !== null) {
            throw new Refusal('Van offerte ' . $quote->number . ' is al een werkbon gemaakt.');
        }

        return DB::transaction(function () use ($quote) {
            $order = $this->create_order->execute(new NewServiceOrder(
                customer_id: $quote->customer_id,
                project_id: $quote->project_id,
                location_id: $quote->location_id,
                description: 'Offerte ' . $quote->number . ': ' . $quote->title,
            ));

            $lines = $quote->lines()->with('item')->get();
            $children = $lines->whereNotNull('parent_id')->groupBy('parent_id');

            $lines->whereNull('parent_id')
                ->filter(fn (QuoteLine $line) => $line->isIncluded())
                ->each(fn (QuoteLine $line) => $this->carryOver($order, $line, $children->get($line->id, collect())));

            $quote->forceFill(['service_order_id' => $order->id])->save();

            Signals::dispatch(new QuoteConvertedToServiceOrder($quote, $order));

            return $order;
        });
    }

    /** @param Collection<int, QuoteLine> $children */
    private function carryOver(ServiceOrder $order, QuoteLine $line, Collection $children): void
    {
        match ($line->kindEnum()) {
            QuoteLineKinds::product => $this->asTask($order, $line, $children),
            QuoteLineKinds::material, QuoteLineKinds::free => $this->book($order, $line),
            QuoteLineKinds::text => null,
        };
    }

    /** @param Collection<int, QuoteLine> $children */
    private function asTask(ServiceOrder $order, QuoteLine $line, Collection $children): void
    {
        $task = $this->create_task->execute([
            'service_order_id' => $order->id,
            'product_id' => $line->item instanceof Product ? $line->item->id : null,
            'quantity' => max(1, (int) round($line->quantity)),
            'title' => self::named($line),
        ]);

        foreach ($children as $child) {
            $this->book($task, $child);
        }
    }

    private static function named(QuoteLine $line): string
    {
        return $line->description . ($line->location_note ? ' (' . $line->location_note . ')' : '');
    }

    /** A catalogue material as planned material; anything else, or a material since removed, as a free line. */
    private function book(Model $owner, QuoteLine $line): void
    {
        if ($line->item instanceof Material) {
            $this->materials->attach($owner, $line->item, ['quantity' => $line->quantity, 'unforseen' => false]);

            return;
        }

        $this->materials->createFreeform($owner, [
            'description' => self::named($line),
            'quantity' => $line->quantity,
            'unforseen' => false,
        ]);
    }
}
