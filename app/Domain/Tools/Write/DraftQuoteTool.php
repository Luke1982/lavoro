<?php

namespace App\Domain\Tools\Write;

use App\Actions\Quotes\AddProductToQuoteAction;
use App\Actions\Quotes\AddQuoteLineAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Domain\Quotes\QuoteLineDrafts;
use App\Domain\Signals\Signals;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Enums\QuoteLineKinds;
use App\Exceptions\Refusal;
use App\Http\Requests\QuoteLineStoreRequest;
use App\Http\Requests\QuoteProductStoreRequest;
use App\Http\Requests\QuoteStoreRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Puts an offerte together as a concept: a customer, a title, products from the
 * catalogue and lines of text, in the order given.
 *
 * It stops there. Nothing is sent: a quote goes to a customer from its own page,
 * after somebody has read it. The prices are the catalogue's, copied the way the
 * screen copies them, so the model never names an amount. The materials a product
 * always brings come along under it as they do on the screen. The ones the screen
 * asks about or suggests do not: nobody is asked here, so none of them is added,
 * and they can be put on from the quote's page afterwards.
 */
class DraftQuoteTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    /**
     * A concept is put together from a conversation, so a few dozen lines at
     * most; every product brings its materials and their signals along, and a
     * list longer than this is one to build on the quote's own page.
     */
    public const MAX_LINES = 25;

    public static function name(): string
    {
        return 'draft_quote';
    }

    public function description(): string
    {
        return 'Maakt een concept-offerte voor een klant met producten uit de catalogus en tekstregels. '
            . 'Prijzen komen uit de catalogus; er wordt niets verstuurd. Zoek klant en producten eerst op en '
            . 'roep de tool dan aan: het systeem legt de knop aan de gebruiker voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'De klant van de offerte.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titel, zoals "Twee extra binnendelen".',
                ],
                'lines' => [
                    'type' => 'array',
                    'description' => 'De regels op volgorde: een product met product_id en quantity, '
                        . 'of een tekstregel zonder bedrag met alleen description.',
                    'maxItems' => self::MAX_LINES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer'],
                            'description' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'required' => ['customer_id', 'title', 'lines'],
            'additionalProperties' => false,
        ];
    }

    /** Making a quote and putting lines on it are two rights on the screen; this does both. */
    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Quote::class) && $user->can('update', new Quote);
    }

    /** Klant, producten en aantallen uit een zin halen; de prijzen doet de catalogus. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    /**
     * Every line with its quantity, the standard materials under each product,
     * and every price and the total, so what is agreed to is what will be on
     * it — and that it stays a concept, because "offerte maken" is easily heard
     * as "offerte sturen". Prices are always shown: putting lines on a quote is
     * the right this tool asks, and on the quote's page that right sets them.
     *
     * A proposal that cannot be done is refused before any button is shown, so
     * the refusal's own words only end up here on the click, when the catalogue
     * has moved since; the executor then says why it will not run.
     */
    public function previewOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved->summary ?? 'Deze offerte kan zo niet worden gemaakt.';
        }

        $quote = new Quote;
        $texts = [];
        $total_cents = 0;

        foreach ($resolved['lines'] as $line) {
            if ($line['product'] === null) {
                $texts[] = 'tekst "' . $line['description'] . '"';

                continue;
            }

            $product = new QuoteLine(QuoteLineDrafts::forProduct($quote, $line['product'], (float) $line['quantity']));
            $materials = collect(QuoteLineDrafts::standardMaterialsFor($quote, $line['product'], (float) $line['quantity']))
                ->map(fn (array $draft) => new QuoteLine($draft));

            $total_cents += $product->netCents() + $materials->sum(fn (QuoteLine $material) => $material->netCents());

            $texts[] = $this->describe($product) . ($materials->isEmpty() ? '' : ', met '
                . $materials->map(fn (QuoteLine $material) => $this->describe($material))->implode(', '));
        }

        return 'Concept-offerte "' . $resolved['title'] . '" voor ' . $resolved['customer']->name . ': '
            . implode('; ', $texts)
            . '. Totaal ' . Money::euros($total_cents / 100) . ' excl. btw'
            . '. Prijzen uit de catalogus; de offerte blijft een concept en wordt niet verstuurd.';
    }

    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $create = app(CreateQuoteAction::class);
        $add_product = app(AddProductToQuoteAction::class);
        $add_line = app(AddQuoteLineAction::class);

        try {
            $quote = Signals::asBatch(fn () => DB::transaction(function () use ($resolved, $create, $add_product, $add_line) {
                $quote = $create->execute([
                    'customer_id' => $resolved['customer']->id,
                    'title' => $resolved['title'],
                ]);

                foreach ($resolved['lines'] as $line) {
                    $line['product']
                        ? $add_product->execute($quote, $line['product'], (float) $line['quantity'])
                        : $add_line->execute($quote, QuoteLineKinds::text, [
                            'description' => $line['description'],
                        ]);
                }

                return $quote;
            }));
        } catch (Refusal $refusal) {
            return ToolResult::failed($refusal->getMessage());
        }

        $quote->load('lines');

        return ToolResult::ok(
            [
                'quote_id' => $quote->id,
                'number' => $quote->number,
                'status' => $quote->status,
                'customer_id' => $quote->customer_id,
                'customer' => $resolved['customer']->name,
                'lines' => $quote->lines->map(fn (QuoteLine $line) => array_filter([
                    'description' => $line->description,
                    'kind' => $line->kind,
                    'under_product' => $line->parent_id !== null ? true : null,
                    'quantity' => $line->kind === QuoteLineKinds::text->value ? null : (float) $line->quantity,
                    'unit_price' => $line->kind !== QuoteLineKinds::text->value ? (float) $line->unit_price : null,
                ], fn ($value) => $value !== null))->values()->all(),
                'total_excl_vat' => $quote->totals()->net_cents / 100,
                'link' => '/quotes/' . $quote->id,
                'note' => 'Concept, niet verstuurd. Versturen gaat vanaf de offertepagina.',
            ],
            'Concept-offerte ' . $quote->number . ' gemaakt voor ' . $resolved['customer']->name . '.',
        );
    }

    /** "2x Daikin FTXM25" or "4 meter koelleiding", with its catalogue price. */
    private function describe(QuoteLine $line): string
    {
        $unit = $line->kind === QuoteLineKinds::material->value && filled($line->unit) ? ' ' . $line->unit . ' ' : 'x ';

        return Quantity::human($line->quantity) . $unit . $line->description
            . ' à ' . Money::euros($line->unit_price);
    }

    /**
     * The customer, the title and the lines, checked with the rules of the screens
     * that make a quote and put a line on it.
     *
     * @return ToolResult|array{customer: Customer, title: string,
     *                          lines: array<int, array{product: ?Product, quantity: int, description: ?string}>}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $customer = Customer::find($call->integerArgument('customer_id'));

        if ($customer === null) {
            return ToolResult::notFound('Klant #' . ($call->integerArgument('customer_id') ?? '?'));
        }

        $title = (string) $call->stringArgument('title');
        $quote_fields = $this->asTheFormWould(QuoteStoreRequest::class, ['customer_id' => $customer->id, 'title' => $title]);

        if ($quote_fields->fails()) {
            return ToolResult::failed($this->complaintOf($quote_fields));
        }

        $asked = array_values(array_filter((array) $call->argument('lines', []), 'is_array'));

        if ($asked === []) {
            return ToolResult::failed('Geef minstens één regel: een product of een tekst.');
        }

        if (count($asked) > self::MAX_LINES) {
            return ToolResult::failed('Dat zijn ' . count($asked) . ' regels; een concept-offerte van de assistent '
                . 'heeft er hoogstens ' . self::MAX_LINES . '. Maak hem met de eerste ' . self::MAX_LINES
                . ' en zet de rest op de offertepagina.');
        }

        $products = Product::where('active', true)
            ->whereIn('id', array_filter(array_map('intval', array_column($asked, 'product_id'))))
            ->with(['brand', 'materialDefaults.material.usageUnit'])
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($asked as $line) {
            $product_id = (int) ($line['product_id'] ?? 0);

            if ($product_id > 0) {
                if (!$products->has($product_id)) {
                    return ToolResult::notFound('Product #' . $product_id . ' in de catalogus');
                }

                $quantity = (int) ($line['quantity'] ?? 1);
                $checked = $this->asTheFormWould(QuoteProductStoreRequest::class, [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                ]);

                if ($checked->fails()) {
                    return ToolResult::failed($this->complaintOf($checked));
                }

                $lines[] = ['product' => $products[$product_id], 'quantity' => $quantity, 'description' => null];

                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            $checked = $this->asTheFormWould(
                QuoteLineStoreRequest::class,
                ['kind' => QuoteLineKinds::text->value, 'description' => $description],
                $call->user,
                ['quote' => new Quote],
            );

            if ($checked->fails()) {
                return ToolResult::failed($this->complaintOf($checked));
            }

            $lines[] = ['product' => null, 'quantity' => 0, 'description' => $description];
        }

        return ['customer' => $customer, 'title' => $title, 'lines' => $lines];
    }
}
