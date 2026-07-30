<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;

/**
 * Searches the catalogue: what can be sold and installed.
 *
 * Distinct from find_asset, which finds machines that already stand somewhere. A
 * new installation has no machine yet — it has a product — and without this the
 * assistant cannot even ask which airco is going in, because it has no way to
 * name one. It was reduced to writing "airco" in a description and leaving
 * whoever picked up the werkbon to work the rest out.
 */
class FindProductTool implements Tool
{
    use OffersAChoice;

    public static function name(): string
    {
        return 'find_products';
    }

    public function description(): string
    {
        return 'Zoekt producten in het assortiment op merk, model of soort — dus wat er geïnstalleerd '
            . 'of verkocht kan worden, niet wat er al staat. Gebruik dit als je moet weten welk '
            . 'apparaat het betreft, bijvoorbeeld bij een nieuwe installatie.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Zoektekst in merk, model of omschrijving.',
                ],
                'product_type' => [
                    'type' => 'string',
                    'description' => 'Beperk tot dit soort product, bijvoorbeeld Airco of Boiler.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Een merk of model uit een vraag halen en er de bijbehorende regels bij zoeken. */
    public static function difficulty(): int
    {
        return 2;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $limit = (int) config('assistant.max_results', 25);

        $query = Product::query()
            ->visibleTo($call->user)
            ->withAttributeData()
            ->with(['brand:id,name', 'productType:id,name']);

        if ($search = $call->stringArgument('query')) {
            $like = $call->likeArgument('query');
            $query->where(fn ($q) => $q
                ->where('model', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('part_no', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))
                /**
                 * The recorded attributes count as searchable text. A capacity, a
                 * colour, a connection size — where an installation fills those in,
                 * "200 m3/h" is the most direct thing somebody can say, and it
                 * appears nowhere in a model number.
                 */
                ->orWhereHas('productAttributeValueables.value', fn ($v) => $v->where('value', 'like', $like)));
        }

        if ($type = $call->stringArgument('product_type')) {
            $query->whereHas('productType', fn ($t) => $t->where('name', 'like', $call->likeArgument('product_type')));
        }

        $products = $query->orderBy('id')->limit($limit)->get();

        if ($products->isEmpty()) {
            return $this->nothingFound($call);
        }

        $rows = $products->map(fn (Product $product) => $this->rowFor($product))->all();

        $content = ['products' => $rows];

        /**
         * A handful of matches with no way to tell which was meant is a choice, and
         * it comes from here rather than from the model remembering to ask. Asked
         * the same thing twice it offered buttons once and a bulleted list the next.
         */
        $choice = $this->choiceOf(
            $products,
            'Welk product bedoel je?',
            'product',
            'products',
            fn ($product) => trim($product->display_name . ' — ' . ($product->productType?->name ?? ''), ' —'),
        );

        if ($choice !== null) {
            $content['choice'] = $choice;
            $content['note'] = 'De gebruiker krijgt hier knoppen voor te zien. Som ze niet nog eens op; '
                . 'zeg kort wat je vond en laat hem kiezen.';
        }

        return ToolResult::ok(
            $content,
            count($rows) . ' product(en) gevonden.',
        );
    }

    /**
     * One product, with whatever this installation records about it.
     *
     * The description and the recorded attributes come too. A capacity is often not
     * an attribute at all — SRK 25 ZS-W is the 2,5 kW one — so whoever reads this
     * needs the text to work from rather than a tidy field that does not exist.
     *
     * @return array<string, mixed>
     */
    private function rowFor(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->display_name,
            'brand' => $product->brand?->name,
            'model' => $product->model,
            'type' => $product->productType?->name,
            'description' => $product->description,
            'part_no' => $product->part_no,
            'attributes' => $product->specific_attributes ?? [],
        ];
    }

    /**
     * Says what would have worked, rather than only that nothing did.
     *
     * Asked for a Mitsubishi airco the assistant searched "MSZ" — the naming
     * Mitsubishi uses in the world, not the one this catalogue uses — found
     * nothing, reported there were no aircos, and when told otherwise invented six
     * model numbers against unrelated product ids. There were twenty-five
     * Mitsubishi products all along, and "Mitsubishi" on its own would have found
     * them.
     *
     * An empty answer that names the brands and types it does hold leaves nothing
     * to guess at.
     */
    private function nothingFound(ToolCall $call): ToolResult
    {
        $words = preg_split('/\s+/', (string) $call->stringArgument('query'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $brands = Brand::query()
            ->when($words !== [], fn ($q) => $q->where(function ($inner) use ($words) {
                foreach ($words as $word) {
                    $inner->orWhere('name', 'like', '%' . addcslashes($word, '%_\\') . '%');
                }
            }))
            ->whereHas('products')
            ->orderBy('name')
            ->limit(10)
            ->pluck('name');

        $wanted_type = $call->stringArgument('product_type');

        /**
         * Counted, and only the ones that hold anything.
         *
         * Asked which outdoor units would fit, the assistant found none and had no
         * way to tell whether that meant "none in stock" or "wrong search". It
         * waffled and then produced a list it had never been given. A type that
         * exists and is empty is a fact worth being able to state: there are no
         * loose buitendelen here, there are twenty-six airco sets.
         */
        $types = ProductType::query()
            ->whereHas('products')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->limit(15)
            ->get()
            ->mapWithKeys(fn (ProductType $type) => [$type->name => $type->products_count]);

        $empty_type = filled($wanted_type)
            ? ProductType::query()
                ->where('name', 'like', $call->likeArgument('product_type'))
                ->whereDoesntHave('products')
                ->orderBy('name')
                ->pluck('name')
            : collect();

        /**
         * The candidates themselves, not merely the names of brands and types.
         *
         * "Een Mitsubishi 2,5 kW" matches no text in the catalogue, because the
         * capacity is not an attribute here — it is inside the model name, where
         * SRK 25 ZS-W is the 2,5 kW one. Nobody can answer that from a list of
         * brands. Given the rows, it is not a hard question; given only "niets
         * gevonden", the last assistant invented six model numbers.
         */
        $candidates = Product::query()
            ->visibleTo($call->user)
            ->withAttributeData()
            ->with(['brand:id,name', 'productType:id,name'])
            ->when($brands->isNotEmpty(), fn ($q) => $q->whereHas('brand', fn ($b) => $b->whereIn('name', $brands)))
            ->when(
                filled($call->stringArgument('product_type')),
                fn ($q) => $q->whereHas('productType', fn ($t) => $t->where('name', 'like', $call->likeArgument('product_type')))
            )
            ->orderBy('id')
            ->limit((int) config('assistant.max_results', 25))
            ->get();

        return ToolResult::ok(
            [
                'products' => [],
                'brands_that_do_exist' => $brands->all(),
                'types_that_do_exist' => $types->all(),
                'types_that_are_empty' => $empty_type->all(),
                'candidates' => $candidates->map(fn (Product $product) => $this->rowFor($product))->all(),
                'note' => ($empty_type->isNotEmpty()
                    ? 'Het soort dat je zoekt (' . $empty_type->implode(', ') . ') bestaat wel, maar er '
                        . 'staan geen producten in. Zeg dat gewoon: dat is het antwoord. '
                    : 'Niets gevonden op precies deze tekst. ')
                    . 'Hierboven staat welke soorten er wel zijn en hoeveel producten erin zitten, en '
                    . 'welke producten bij dit merk of type horen. Een vermogen zit vaak in het '
                    . 'modelnummer, bijvoorbeeld SRK 25 voor 2,5 kW. Weet je het niet zeker, leg de '
                    . 'kandidaten met ask_which_one voor. Verzin nooit een product dat hier niet '
                    . 'tussen staat, en beweer nooit dat je een lijst hebt gegeven als je dat niet hebt.',
            ],
            $candidates->isEmpty()
                ? 'Geen producten gevonden.'
                : 'Niets op die tekst; ' . $candidates->count() . ' product(en) van dat merk of type.',
        );
    }
}
