<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\ChangeQuoteStatusAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Actions\Quotes\DeleteQuoteAction;
use App\Actions\Quotes\UpdateQuoteAction;
use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteLineTree;
use App\Domain\Quotes\QuoteMail;
use App\Domain\Quotes\VatRates;
use App\Enums\QuoteStatuses;
use App\Http\Requests\QuoteDestroyRequest;
use App\Http\Requests\QuoteReadRequest;
use App\Http\Requests\QuoteStatusUpdateRequest;
use App\Http\Requests\QuoteStoreRequest;
use App\Http\Requests\QuoteUpdateRequest;
use App\Models\MaterialUsageUnit;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteUnit;
use App\Models\QuoteVersion;
use App\Models\ServiceOrder;
use App\Traits\OffersCustomerPicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class QuoteController extends Controller
{
    use OffersCustomerPicker;

    private const LIST_COLUMNS = ['id', 'number', 'title', 'status', 'quote_date', 'valid_until'];

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
                ...Arr::only($quote->attributesToArray(), self::LIST_COLUMNS),
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
            'customer:id,name,city,email,quotes_email',
            'customer.locations:id,customer_id,title,address,city',
            'customer.contacts',
            'customer.projects:id,customer_id,number,title',
            'contact',
            'serviceOrder',
            'answeredLinks',
            'documents.category',
            'documents.user:id,name',
            'versions',
            'lines',
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        if ($see_margins) {
            $quote->lines->each->makeVisible(QuoteLine::MARGIN_ATTRIBUTES);
        }

        $lines = QuoteLineTree::of($quote);
        $totals = $quote->totals()->toArray($see_margins);
        $answers = $quote->answeredLinks->map->answerSummary()->values();
        $service_order = $quote->serviceOrder?->only(['id', 'number']);
        $versions = $quote->versions
            ->map(fn (QuoteVersion $version) => $version->toListItem($quote->accepted_version_id))
            ->values();
        $quote->setRelations(Arr::only($quote->getRelations(), ['customer', 'contact', 'documents', 'activities']))
            ->makeVisible('accepted_signature');

        return inertia('Quotes/ShowPage', [
            'quote' => $quote,
            'lines' => $lines,
            'totals' => $totals,
            'vatRates' => VatRates::ALL,
            'units' => self::units(),
            'answers' => $answers,
            'versions' => $versions,
            'acceptedVia' => $quote->accepted_via?->phrase(),
            'serviceOrder' => $service_order,
            'sendDefaults' => QuoteMail::defaults($quote),
            'nextStatuses' => array_map(fn (QuoteStatuses $status) => $status->value, $quote->statusesByHand()),
            'editable' => $quote->statusEnum()->allowsEditing(),
            'acceptable' => $quote->statusEnum()->allowsAccepting(),
            'can' => [
                'update' => $request->user()->can('update', $quote),
                'delete' => $request->user()->can('delete', $quote),
                'seeMargins' => $see_margins,
                'createServiceOrder' => $request->user()->can('create', ServiceOrder::class),
            ],
        ]);
    }

    /** The quote as the customer will see it, opened by whoever makes it: the same page, without the answer. */
    public function preview(QuoteReadRequest $request, Quote $quote)
    {
        return inertia('Public/QuoteResponsePage', [
            ...QuoteForCustomer::of($quote),
            'open' => true,
            'preview_of' => route('quotes.show', $quote),
        ]);
    }

    /** A version as the customer received it, opened by a colleague: frozen, nothing to send from it. */
    public function version(QuoteReadRequest $request, Quote $quote, QuoteVersion $version)
    {
        return inertia('Public/QuoteResponsePage', [
            ...$version->snapshot,
            'version' => $version->number,
            'open' => true,
            'preview_of' => route('quotes.show', $quote),
            'preview_note' => 'Versie ' . $version->number . ', ' . ($version->recipient
                ? 'verstuurd op ' . $version->created_at->format('d-m-Y') . ' aan ' . $version->recipient
                : 'vastgelegd op ' . $version->created_at->format('d-m-Y')) . '.',
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

        return redirect()->back()
            ->with('success', 'Offerte ' . $quote->number . ' is nu ' . mb_strtolower($quote->status) . '.');
    }

    public function destroy(QuoteDestroyRequest $request, Quote $quote, DeleteQuoteAction $delete)
    {
        $delete->execute($quote);

        return redirect()->route('quotes.index')->with('success', 'Offerte verwijderd.');
    }

    /**
     * The units a line is offered in: the company's own, in their order, and the
     * usage units of the materials, which material lines carry.
     *
     * @return array<int, string>
     */
    private static function units(): array
    {
        return QuoteUnit::orderBy('position')->pluck('name')
            ->merge(MaterialUsageUnit::orderBy('name')->pluck('name'))
            ->unique()
            ->values()
            ->all();
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
            ->when($list['customer'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when(match ($list['status']) {
                QuoteReadRequest::OPEN => QuoteStatuses::openValues(),
                QuoteReadRequest::EVERY_STATUS => null,
                default => [$list['status']],
            }, fn (Builder $query, array $statuses) => $query->whereIn('status', $statuses));
    }
}
