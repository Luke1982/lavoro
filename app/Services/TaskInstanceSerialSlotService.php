<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Support\Collection;

/**
 * Expands the product on a werkbon taak into the machines it is expected to deliver, and
 * matches the ones already registered against them.
 *
 * A plain product of aantal 3 expects three machines of itself. A bundle expects one bundle
 * machine per sold bundle — carrying no serial of its own — with its parts underneath, so a
 * taak for 2 bundles of "1 omvormer + 3 panelen" expects two bundles, each with 1 and 3
 * parts. A part whose aantal is flex counts for whatever was filled in on the taak.
 *
 * A slot is addressed by the bundle machine it sits in as well as by its product, since the
 * same part appears once per bundle. Slots fill one machine at a time and the taak only
 * completes once every slot is full, so this is the single answer to "what is still missing"
 * for both the drawer and the completion guard. A slot for a product that carries no serial
 * still has to be registered — it just has nothing to type.
 */
class TaskInstanceSerialSlotService
{
    public function __construct(private readonly TaskInstanceBundleService $bundles) {}

    /**
     * How a slot is addressed from the outside: by the bundle machine it sits in, if any,
     * and by the product it expects.
     */
    public static function slotKey(?int $container_index, int $product_id): string
    {
        return ($container_index === null ? 'p' : 'c' . $container_index) . ':' . $product_id;
    }

    /**
     * One entry per expected product, oldest registered machine first.
     *
     * @return array<int, array{key: string, container_index: ?int, container_label: ?string,
     *                          product_id: int, label: string, requires_serial: bool,
     *                          expected: int, assets: array<int, array{id: int, serial_number: ?string}>}>
     */
    public function groups(ServiceOrderTaskInstance $instance): array
    {
        $product = $instance->product;

        if (!$product) {
            return [];
        }

        $quantity = max(1, (int) ($instance->quantity ?? 1));

        if (!$product->bundle) {
            return [$this->slot(null, null, $product, $quantity, $instance->assets->sortBy('id'))];
        }

        $parts = $this->bundles->parts($instance);
        $containers = $this->bundles->containers($instance);
        $groups = [];

        for ($index = 0; $index < $quantity; $index++) {
            $container = $containers[$index] ?? null;
            $container_label = $this->label($product)
                . ($quantity > 1 ? ' ' . ($index + 1) . '/' . $quantity : '');

            foreach ($parts as $part) {
                $groups[] = $this->slot(
                    $index,
                    $container_label,
                    $part['product'],
                    $part['quantity'],
                    $this->bundles->partsOf($instance, $container, $part['product']->id),
                );
            }
        }

        return $groups;
    }

    /**
     * A taak without a product registers no machines, so it has nothing left to fill.
     */
    public function allSlotsFilled(ServiceOrderTaskInstance $instance): bool
    {
        return collect($this->groups($instance))
            ->every(fn (array $group) => count($group['assets']) >= $group['expected']);
    }

    /**
     * @param  Collection<int, Asset>  $registered
     * @return array<string, mixed>
     */
    private function slot(
        ?int $container_index,
        ?string $container_label,
        Product $product,
        int $expected,
        Collection $registered,
    ): array {
        return [
            'key' => self::slotKey($container_index, $product->id),
            'container_index' => $container_index,
            'container_label' => $container_label,
            'product_id' => $product->id,
            'label' => $this->label($product),
            'requires_serial' => $product->requiresSerial(),
            'expected' => $expected,
            'assets' => $registered
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'serial_number' => $asset->serial_number,
                ])
                ->values()
                ->all(),
        ];
    }

    private function label(Product $product): string
    {
        return collect([$product->brand?->name, $product->model])
            ->filter()
            ->implode(' ');
    }
}
