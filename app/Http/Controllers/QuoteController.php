<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\ChangeQuoteStatusAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Actions\Quotes\DeleteQuoteAction;
use App\Actions\Quotes\UpdateQuoteAction;
use App\Domain\Quotes\VatRates;
use App\Enums\QuoteStatuses;
use App\Http\Requests\QuoteDestroyRequest;
use App\Http\Requests\QuoteReadRequest;
use App\Http\Requests\QuoteStatusUpdateRequest;
use App\Http\Requests\QuoteStoreRequest;
use App\Http\Requests\QuoteUpdateRequest;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Traits\OffersCustomerPicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class QuoteController extends Controller
{
    use OffersCustomerPicker;

    public function index(QuoteReadRequest $request)
    {
        $list = $request->listQuery();

        $quotes = Quote::query()
            ->with(['customer:id,name', 'lines'])
            ->tap(fn (Builder $query) => $this->filter($query, $list))
            ->orderByDesc('quote_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quote $quote) => [
                ...Arr::only($quote->attributesToArray(), ['id', 'number', 'title', 'status', 'quote_date', 'valid_until']),
                'customer' => $quote->customer?->only(['id', 'name']),
                'total' => $quote->totals()->net_cents / 100,
            ]);

        return inertia('Quotes/IndexPage', [
            'quotes' => $quotes,
            ...$this->customerPicker($list['customer']),
            'statuses' => QuoteStatuses::comboBoxArray(),
            'list' => $list,
        ]);
    }

    public function show(QuoteReadRequest $request, Quote $quote)
    {
        $see_margins = $request->user()->can('seeMargins', Quote::class);

        $quote->load([
            'customer:id,name,city',
            'customer.locations:id,customer_id,title,address,city',
            'customer.contacts',
            'customer.projects:id,customer_id,number,title',
            'lines',
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        if ($see_margins) {
            $quote->lines->each->makeVisible(QuoteLine::MARGIN_ATTRIBUTES);
        }

        $lines = $this->tree($quote);
        $totals = $quote->totals()->toArray($see_margins);
        $quote->unsetRelation('lines');

        return inertia('Quotes/ShowPage', [
            'quote' => $quote,
            'lines' => $lines,
            'totals' => $totals,
            'vatRates' => VatRates::ALL,
            'nextStatuses' => array_map(fn (QuoteStatuses $status) => $status->value, $quote->statusEnum()->next()),
            'editable' => $quote->statusEnum()->allowsEditing(),
            'can' => [
                'update' => $request->user()->can('update', $quote),
                'delete' => $request->user()->can('delete', $quote),
                'seeMargins' => $see_margins,
            ],
        ]);
    }

    public function store(QuoteStoreRequest $request, CreateQuoteAction $create)
    {
        $quote = $create->execute($request->validated());

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Offerte ' . $quote->number . ' aangemaakt.');
    }

    public function update(QuoteUpdateRequest $request, Quote $quote, UpdateQuoteAction $update)
    {
        $update->execute($quote, $request->validated());

        return redirect()->back()->with('success', 'Offerte bijgewerkt.');
    }

    public function status(QuoteStatusUpdateRequest $request, Quote $quote, ChangeQuoteStatusAction $change)
    {
        $change->execute($quote, $request->enum('status', QuoteStatuses::class));

        return redirect()->back()->with('success', 'Offerte ' . $quote->number . ' is nu ' . mb_strtolower($quote->status) . '.');
    }

    public function destroy(QuoteDestroyRequest $request, Quote $quote, DeleteQuoteAction $delete)
    {
        $delete->execute($quote);

        return redirect()->route('quotes.index')->with('success', 'Offerte verwijderd.');
    }

    /** @param array{search: string, status: string, customer: ?int} $list */
    private function filter(Builder $query, array $list): void
    {
        $like = '%' . $list['search'] . '%';

        $query
            ->when($list['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like))))
            ->when($list['customer'], fn (Builder $query, int $customer_id) => $query->where('customer_id', $customer_id))
            ->when(match ($list['status']) {
                QuoteReadRequest::OPEN => QuoteStatuses::openValues(),
                QuoteReadRequest::EVERY_STATUS => null,
                default => [$list['status']],
            }, fn (Builder $query, array $statuses) => $query->whereIn('status', $statuses));
    }

    /**
     * The lines as the screen draws them: each line at the top, with the
     * materials that came along with it underneath.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tree(Quote $quote): array
    {
        $row = fn (QuoteLine $line) => [...$line->toArray(), 'net' => $line->netCents() / 100];
        $children = $quote->lines->whereNotNull('parent_id')->groupBy('parent_id');

        return $quote->lines
            ->whereNull('parent_id')
            ->map(fn (QuoteLine $line) => [
                ...$row($line),
                'children' => $children->get($line->id, collect())->map($row)->values()->all(),
            ])
            ->values()
            ->all();
    }
}
