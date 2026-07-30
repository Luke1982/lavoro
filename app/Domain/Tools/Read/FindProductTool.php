<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Product;
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

        $rows = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->display_name,
            'brand' => $product->brand?->name,
            'model' => $product->model,
            'type' => $product->productType?->name,
        ])->all();

        return ToolResult::ok(
            ['products' => $rows],
            count($rows) . ' product(en) gevonden.',
        );
    }
}
