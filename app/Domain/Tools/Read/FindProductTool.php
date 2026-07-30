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
            ->with(['brand:id,name', 'productType:id,name']);

        if ($search = $call->stringArgument('query')) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q
                ->where('model', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like)));
        }

        if ($type = $call->stringArgument('product_type')) {
            $query->whereHas('productType', fn ($t) => $t->where('name', 'like', '%' . $type . '%'));
        }

        $products = $query->orderBy('id')->limit($limit)->get();

        if ($products->isEmpty()) {
            return $this->nothingFound($call);
        }

        $rows = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->display_name,
            'brand' => $product->brand?->name,
            'model' => $product->model,
            'type' => $product->productType?->name,
        ])->all();

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
                    $inner->orWhere('name', 'like', '%' . $word . '%');
                }
            }))
            ->whereHas('products')
            ->orderBy('name')
            ->limit(10)
            ->pluck('name');

        $types = ProductType::query()
            ->when(
                filled($call->stringArgument('product_type')),
                fn ($q) => $q->where('name', 'like', '%' . $call->stringArgument('product_type') . '%')
            )
            ->whereHas('products')
            ->orderBy('name')
            ->limit(12)
            ->pluck('name');

        return ToolResult::ok(
            [
                'products' => [],
                'brands_that_do_exist' => $brands->all(),
                'types_that_do_exist' => $types->all(),
                'note' => 'Niets gevonden op deze zoektekst. Verzin geen productnamen: zoek opnieuw op '
                    . 'een merk of een producttype uit de lijsten hierboven, of zeg dat het er niet in '
                    . 'staat. Een modelnummer dat je niet uit een tool hebt bestaat hier niet.',
            ],
            'Geen producten gevonden; wel merken en types om op te zoeken.',
        );
    }
}
