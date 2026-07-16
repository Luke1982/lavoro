<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;

/**
 * Expands the product on a werkbon task into the machines it is expected to deliver,
 * and matches the ones already registered against them.
 *
 * A plain product of quantity 3 expects three serials of itself. A bundle carries none
 * of its own — it expects each of its components, times the bundle's own quantity — so a
 * task for 2 bundles of "1 outdoor unit + 3 indoor units" expects 2 and 6 respectively.
 *
 * Slots fill one machine at a time and the task only completes once every slot is filled,
 * so this is the single answer to "how many are still missing" for both the drawer and
 * the completion guard.
 */
class TaskInstanceSerialSlotService
{
    /**
     * One entry per expected product, oldest registered machine first.
     *
     * @return array<int, array{product_id: int, label: string, expected: int,
     *                          assets: array<int, array{id: int, serial_number: ?string}>}>
     */
    public function groups(ServiceOrderTaskInstance $instance): array
    {
        $registered = $instance->assets
            ->sortBy('id')
            ->groupBy('product_id');

        return collect($this->expectedCounts($instance))
            ->map(fn (array $expected) => [
                'product_id' => $expected['product_id'],
                'label' => $expected['label'],
                'expected' => $expected['count'],
                'assets' => ($registered[$expected['product_id']] ?? collect())
                    ->map(fn ($asset) => [
                        'id' => $asset->id,
                        'serial_number' => $asset->serial_number,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{product_id: int, label: string, count: int}>
     */
    public function expectedCounts(ServiceOrderTaskInstance $instance): array
    {
        $product = $instance->product;

        if (!$product) {
            return [];
        }

        $quantity = max(1, (int) ($instance->quantity ?? 1));

        if (!$product->bundle) {
            return [[
                'product_id' => $product->id,
                'label' => $this->label($product),
                'count' => $quantity,
            ]];
        }

        return $product->productables
            ->filter(fn ($productable) => $productable->childProduct !== null)
            ->map(fn ($productable) => [
                'product_id' => $productable->childProduct->id,
                'label' => $this->label($productable->childProduct),
                'count' => max(1, (int) ($productable->quantity ?? 1)) * $quantity,
            ])
            ->values()
            ->all();
    }

    public function expectsProduct(ServiceOrderTaskInstance $instance, int $product_id): bool
    {
        return collect($this->expectedCounts($instance))
            ->contains(fn (array $expected) => $expected['product_id'] === $product_id);
    }

    /**
     * How many more machines of a product the task still expects. Never negative: a task
     * carrying more than it expects is over-full, not short.
     */
    public function remainingFor(ServiceOrderTaskInstance $instance, int $product_id): int
    {
        $expected = collect($this->expectedCounts($instance))
            ->firstWhere('product_id', $product_id);

        if (!$expected) {
            return 0;
        }

        $registered = $instance->assets->where('product_id', $product_id)->count();

        return max(0, $expected['count'] - $registered);
    }

    /**
     * A task without a product registers no machines, so it has nothing left to fill.
     */
    public function allSlotsFilled(ServiceOrderTaskInstance $instance): bool
    {
        return collect($this->expectedCounts($instance))
            ->every(fn (array $expected) => $instance->assets
                ->where('product_id', $expected['product_id'])
                ->count() >= $expected['count']);
    }

    public function filledCount(ServiceOrderTaskInstance $instance): int
    {
        return collect($this->expectedCounts($instance))
            ->sum(fn (array $expected) => min(
                $expected['count'],
                $instance->assets->where('product_id', $expected['product_id'])->count(),
            ));
    }

    public function expectedTotal(ServiceOrderTaskInstance $instance): int
    {
        return collect($this->expectedCounts($instance))->sum('count');
    }

    private function label(Product $product): string
    {
        return collect([$product->brand?->name, $product->model])
            ->filter()
            ->implode(' ');
    }
}
