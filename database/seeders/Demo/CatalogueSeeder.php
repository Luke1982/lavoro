<?php

namespace Database\Seeders\Demo;

use App\Models\Brand;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use App\Models\ServiceCheckValue;
use App\Support\Demo\ProductArt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The product type tree, the checklists a mechanic fills in per type, and a
 * catalogue of real products with a picture each.
 */
final class CatalogueSeeder
{
    /** @var array<string, array{art: string, checks: string}> leaf type name => details */
    private array $leaves = [];

    public function __construct(private DemoContext $context) {}

    public function run(): void
    {
        $catalogue = $this->context->data('catalogue');

        $brands = collect($catalogue['brands'])
            ->mapWithKeys(fn (array $brand, string $name) => [$name => Brand::create(['name' => $name])]);

        foreach ($catalogue['types'] as $name => $node) {
            $this->type($name, $node, null, $name, $node['certificate_days'] ?? null);
        }

        $this->checklists();

        foreach ($catalogue['products'] as [$type, $brand, $model, $description, $retail, $purchase, $warranty]) {
            $product = Product::forceCreate([
                'product_type_id' => $this->context->types[$type]->id,
                'brand_id' => $brands[$brand]->id,
                'model' => $model,
                'description' => $description,
                'part_no' => Str::of($model)->afterLast(' ')->upper()->toString(),
                'retail_price' => $retail,
                'purchase_price' => $purchase,
                'warranty' => $warranty,
                'registable' => true,
                'active' => true,
                'start_sell' => $this->context->now->subYears(2)->startOfYear()->toDateString(),
            ]);

            $this->picture($product, $brand, $model, $catalogue['brands'][$brand]['accent'], $this->leaves[$type]['art']);

            $this->context->products[$type][] = $product;
        }
    }

    private function type(string $name, array $node, ?ProductType $parent, string $family, ?int $certificate_days): void
    {
        $type = ProductType::create([
            'name' => $name,
            'parent_id' => $parent?->id,
            'typical_certificate_days' => $node['certificate_days'] ?? $certificate_days,
        ]);

        if (isset($node['children'])) {
            foreach ($node['children'] as $child_name => $child) {
                $this->type($child_name, $child, $type, $family, $node['certificate_days'] ?? $certificate_days);
            }

            return;
        }

        $this->leaves[$name] = ['art' => $node['art'], 'checks' => $node['checks']];
        $this->context->types[$name] = $type;
        $this->context->families[$name] = $family;
    }

    /**
     * One question is one check, however many lists it is on: "Condensafvoer
     * vrij" on the airco list and on the ventilation list is the same question,
     * and the checks screen should not show it twice.
     */
    private function checklists(): void
    {
        $checklists = $this->context->data('checklists');

        $groups = collect($checklists['groups'])->values()
            ->mapWithKeys(fn (string $name, int $order) => [$name => ServiceCheckGroup::create(['name' => $name, 'order' => $order + 1])]);

        /** @var array<string, ServiceCheck> $checks */
        $checks = [];

        foreach ($checklists['lists'] as $list => $questions) {
            $types = collect($this->leaves)->filter(fn (array $leaf) => $leaf['checks'] === $list)
                ->keys()->map(fn (string $name) => $this->context->types[$name]->id);

            foreach ($questions as $position => [$group, $question, $kind]) {
                $details = $questions[$position][3] ?? [];

                $checks[$question] ??= $this->check($question, $kind, $details, $groups[$group]->id, count($checks) + 1);

                $checks[$question]->productTypes()->syncWithoutDetaching($types);
            }
        }
    }

    private function check(string $question, string $kind, array $details, int $group_id, int $order): ServiceCheck
    {
        $check = ServiceCheck::forceCreate([
            'name' => $question,
            'type' => $kind,
            'order' => $order,
            'service_check_group_id' => $group_id,
        ]);

        foreach ($details['options'] ?? [] as $position => $option) {
            ServiceCheckValue::forceCreate(['service_check_id' => $check->id, 'order' => $position, 'value' => $option]);
        }

        return $check;
    }

    /**
     * A real photo when one was put in place, the drawing otherwise.
     */
    private function picture(Product $product, string $brand, string $model, string $accent, string $art): void
    {
        $slug = Str::slug($brand . ' ' . $model);
        $photo = collect(['jpg', 'jpeg', 'png', 'webp'])
            ->map(fn (string $extension) => base_path("database/seeders/data/demo/photos/products/{$slug}.{$extension}"))
            ->first(fn (string $path) => is_file($path));

        $path = "uploaded/product/{$product->id}/" . ($photo ? basename($photo) : "{$slug}.svg");

        Storage::disk('public')->put($path, $photo ? File::get($photo) : ProductArt::render($art, $brand, $model, $accent));

        $image = Image::create(['name' => "{$brand} {$model}", 'path' => $path]);

        $product->images()->attach($image->id, ['main' => true]);
    }
}
