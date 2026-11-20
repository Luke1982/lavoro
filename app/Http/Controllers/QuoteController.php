<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\ChangeQuoteStatusAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Actions\Quotes\DeclineQuoteAction;
use App\Actions\Quotes\DeleteQuoteAction;
use App\Actions\Quotes\UpdateQuoteAction;
use App\Domain\Quotes\QuoteFiles;
use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteLayout;
use App\Domain\Quotes\QuoteLineTree;
use App\Domain\Quotes\QuoteMail;
use App\Domain\Quotes\QuoteOrigins;
use App\Domain\Quotes\QuotePdf;
use App\Domain\Quotes\QuoteSettings;
use App\Domain\Quotes\VatRates;
use App\Enums\QuotePricingMethods;
use App\Enums\QuoteStatuses;
use App\Http\Requests\QuoteDestroyRequest;
use App\Http\Requests\QuoteReadRequest;
use App\Http\Requests\QuoteStatusUpdateRequest;
use App\Http\Requests\QuoteStoreRequest;
use App\Http\Requests\QuoteUpdateRequest;
use App\Models\MaterialUsageUnit;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteLostReason;
use App\Models\QuoteTemplate;
use App\Models\QuoteUnit;
use App\Models\QuoteVersion;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Traits\OffersCustomerPicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class QuoteController extends Controller
{
    use OffersCustomerPicker;

    private const LIST_COLUMNS = ['id', 'number', 'title', 'status', 'quote_date', 'valid_until'];

    /** What the quote page gets of the relations it loaded; the rest it gets in a shape of its own. */
    private const SHOWN_RELATIONS = ['customer', 'contact', 'documents', 'activities', 'lostReason'];

    public function index(QuoteReadRequest $request)
    {
        $list = $request->listQuery();

        $quotes = Quote::query()
            ->with(['customer:id,name', 'lines', 'lostReason:id,name'])
            ->tap(fn (Builder $query) => $this->filter($query, $list))
            ->orderByDesc('quote_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quote $quote) => [
                ...Arr::only($quote->attributesToArray(), self::LIST_COLUMNS),
                'customer' => $quote->customer?->only(['id', 'name']),
                'total' => $quote->totals()->net_cents / 100,
                'lost_reason' => $quote->lostReason?->name,
            ]);

        return inertia('Quotes/IndexPage', [
            'quotes' => $quotes,
            ...$this->customerPicker($list['customer']),
            'statuses' => QuoteStatuses::comboBoxArray(),
            'lostReasons' => QuoteLostReason::inOrder()->map->only(['id', 'name']),
            'templates' => QuoteTemplate::orderBy('name')->get(['id', 'name']),
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
            'origin',
            'lostReason',
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
            ->map(fn (QuoteVersion $version) => $version->toListItem($quote))
            ->values();
        $origin = $quote->origin
            ? ['label' => QuoteOrigins::label($quote->origin), 'url' => self::originUrl($quote)]
            : null;
        $version_sent = $quote->versions->contains('number', $quote->version);
        $quote->setRelations(Arr::only($quote->getRelations(), self::SHOWN_RELATIONS))
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
            'versionSent' => $version_sent,
            'termsOnOffer' => $quote->termsOnOffer()['name'] ?? null,
            'coolingOffUntil' => $quote->coolingOffUntil()?->toDateString(),
            'origin' => $origin,
            'layout' => $quote->layoutForCustomer(),
            'layoutChoices' => QuoteLayout::CHOICES,
            'pricingMethods' => QuotePricingMethods::options(),
            'maxUpfrontPercent' => QuoteSettings::maxUpfrontPercent(),
            'lostReasons' => QuoteLostReason::inOrder()->map->only(['id', 'name']),
            ...$this->customerPicker($quote->customer_id),
            'can' => [
                'update' => $request->user()->can('update', $quote),
                'delete' => $request->user()->can('delete', $quote),
                'create' => $request->user()->can('create', Quote::class),
                'seeMargins' => $see_margins,
                'createServiceOrder' => $request->user()->can('create', ServiceOrder::class),
            ],
        ]);
    }

    /** The quote on paper: what was signed, once it was, and otherwise what the customer would get now. */
    public function pdf(QuoteReadRequest $request, Quote $quote): Response
    {
        $signed = QuotePdf::signed($quote);

        $document = $signed ? $quote->signedDocument() : QuoteForCustomer::of($quote);

        return QuoteFiles::inline(QuoteFiles::pdf($document, $signed));
    }

    /** A send on paper as the customer got it; the accepted one as it was signed, with the choices made. */
    public function versionPdf(QuoteReadRequest $request, Quote $quote, QuoteVersion $version): Response
    {
        $signed = $version->id === $quote->accepted_version_id ? QuotePdf::signed($quote) : null;
        $document = $signed ? $quote->signedDocument() : $version->document();

        return QuoteFiles::inline(QuoteFiles::pdf($document, $signed));
    }

    /** The quote as the customer will see it, opened by whoever makes it: the same page, without the answer. */
    public function preview(QuoteReadRequest $request, Quote $quote)
    {
        return inertia('Public/QuoteResponsePage', [
            ...QuoteForCustomer::forPage(QuoteForCustomer::of($quote)),
            'open' => true,
            'preview_of' => route('quotes.show', $quote),
            'pdf_url' => route('quotes.pdf', $quote),
        ]);
    }

    /** A version as the customer received it, opened by a colleague: frozen, nothing to send from it. */
    public function version(QuoteReadRequest $request, Quote $quote, QuoteVersion $version)
    {
        return inertia('Public/QuoteResponsePage', [
            ...QuoteForCustomer::forPage($version->document()),
            'open' => true,
            'preview_of' => route('quotes.show', $quote),
            'pdf_url' => route('quotes.version.pdf', [$quote, $version]),
            'preview_note' => 'Versie ' . $version->number . ', ' . ($version->recipient
                ? 'verstuurd op ' . $version->created_at->format('d-m-Y') . ' aan ' . $version->recipient
                : 'vastgelegd op ' . $version->created_at->format('d-m-Y')) . '.',
        ]);
    }

    /**
     * A new quote: empty, from a template, or from a werkbon or a storing with
     * the lines picked from it.
     */
    public function store(QuoteStoreRequest $request, CreateQuoteAction $create)
    {
        $data = $request->safe()->except(['template_id', 'origin_type', 'origin_id', 'origin_lines']);
        $template = QuoteTemplate::find($request->validated('template_id'));
        $origin = $request->origin() ?? $template;
        $content = match (true) {
            $template !== null => fn () => $template->content,
            $origin !== null => fn (Quote $quote) => QuoteOrigins::content(
                $origin,
                $request->validated('origin_lines', []),
                $quote,
            ),
            default => null,
        };

        $quote = $create->execute($data, $content, $origin);

        return redirect()
            ->route('quotes.show', $quote)
            ->with('success', 'Offerte ' . $quote->number . ' aangemaakt.');
    }

    public function update(QuoteUpdateRequest $request, Quote $quote, UpdateQuoteAction $update)
    {
        $update->execute($quote, $request->validated());

        return redirect()->back()->with('success', 'Offerte bijgewerkt.');
    }

    public function status(
        QuoteStatusUpdateRequest $request,
        Quote $quote,
        ChangeQuoteStatusAction $change,
        DeclineQuoteAction $decline,
    ) {
        $status = $request->enum('status', QuoteStatuses::class);

        if ($status === QuoteStatuses::afgewezen) {
            $decline->execute(
                $quote,
                QuoteLostReason::find($request->validated('lost_reason_id')),
                $request->validated('lost_reason_note'),
            );
        } else {
            $change->execute($quote, $status);
        }

        return redirect()->back()
            ->with('success', 'Offerte ' . $quote->number . ' is nu ' . mb_strtolower($quote->status) . '.');
    }

    public function destroy(QuoteDestroyRequest $request, Quote $quote, DeleteQuoteAction $delete)
    {
        $delete->execute($quote);

        return redirect()->route('quotes.index')->with('success', 'Offerte verwijderd.');
    }

    /** Where what the quote was made from can be opened; a template has no page of its own. */
    private static function originUrl(Quote $quote): ?string
    {
        return match ($quote->origin_type) {
            ServiceOrder::class => '/serviceorders/' . $quote->origin_id,
            Ticket::class => '/tickets/' . $quote->origin_id,
            Quote::class => '/quotes/' . $quote->origin_id,
            default => null,
        };
    }

    /**
     * The units a line is offered in: the company's own, in their order, and the
     * usage units of the materials, which material lines carry.
     *
     * @return array<int, string>
     */
    private static function units(): array
    {
        return QuoteUnit::inOrder()->pluck('name')
            ->merge(MaterialUsageUnit::orderBy('name')->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{search: string, status: string, customer: ?int, reason: ?int} $list */
    private function filter(Builder $query, array $list): void
    {
        $like = '%' . $list['search'] . '%';

        $query
            ->when($list['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like))))
            ->when($list['customer'], fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($list['reason'], fn (Builder $query, int $id) => $query->where('lost_reason_id', $id))
            ->when(match ($list['status']) {
                QuoteReadRequest::OPEN => QuoteStatuses::openValues(),
                QuoteReadRequest::EVERY_STATUS => null,
                default => [$list['status']],
            }, fn (Builder $query, array $statuses) => $query->whereIn('status', $statuses));
    }
}
