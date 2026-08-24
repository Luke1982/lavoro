<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Support\Collection;

/**
 * The bundle side of a werkbon taak: what a bundle taak is made of, and the machines
 * that carry it.
 *
 * A taak for a bundle delivers one machine per bundle it sells — the bundle itself, which
 * carries no serial — with the parts hanging underneath it. That container is created the
 * moment the first part of it is registered, so a taak that is never worked on leaves no
 * machines behind at the customer.
 *
 * A bundle takes its composition from the product, except for the parts whose aantal is
 * flex: one omvormer with however many panelen this roof takes. Those numbers are filled
 * in per taak and live on the taak as productables of its own.
 */
class TaskInstanceBundleService
{
    /**
     * What one bundle of this taak consists of, in the order the product lists its parts.
     * A part with a fixed aantal contributes that many; a flex part contributes whatever
     * was filled in on the taak, and drops out entirely when that is nothing.
     *
     * Every part counts here, verplicht or not: a taak delivers the bundle the customer
     * bought, so its whole composition arrives. Verplicht only decides which parts a
     * hand-made machine starts out with.
     *
     * @return Collection<int, array{product: Product, quantity: int, productable: Productable}>
     */
    public function parts(ServiceOrderTaskInstance $instance): Collection
    {
        $product = $instance->product;

        if (!$product?->bundle) {
            return collect();
        }

        $filled_in = $instance->productables->keyBy(fn (Productable $chosen) => (int) $chosen->product_id);

        return $product->productables
            ->filter(fn (Productable $productable) => $productable->childProduct !== null)
            ->map(function (Productable $productable) use ($filled_in) {
                $part_product_id = (int) $productable->productable_id;

                return [
                    'product' => $productable->childProduct,
                    'quantity' => $productable->flex_quantity
                        ? (int) ($filled_in->get($part_product_id)?->quantity ?? 0)
                        : max(1, (int) ($productable->quantity ?? 1)),
                    'productable' => $productable,
                ];
            })
            ->filter(fn (array $part) => $part['quantity'] > 0)
            ->values();
    }

    /**
     * The bundle machines this taak has produced so far, oldest first. Their position in
     * this list is the index the serial drawer and its validation address them by, so it
     * only ever grows: containers are added at the end and never reordered.
     *
     * @return Collection<int, Asset>
     */
    public function containers(ServiceOrderTaskInstance $instance): Collection
    {
        if (!$instance->product?->bundle) {
            return collect();
        }

        return $instance->assets
            ->whereNull('parent_asset_id')
            ->sortBy('id')
            ->values();
    }

    /**
     * The machines of one product registered under one bundle machine, oldest first.
     *
     * @return Collection<int, Asset>
     */
    public function partsOf(ServiceOrderTaskInstance $instance, ?Asset $container, int $product_id): Collection
    {
        if (!$container) {
            return collect();
        }

        return $this->childrenOf($instance, $container)
            ->where('product_id', $product_id)
            ->sortBy('id')
            ->values();
    }

    /**
     * Brings the taak up to one bundle machine per sold bundle. Called just before parts
     * are registered, so the containers exist by the time a part needs a parent.
     *
     * @return Collection<int, Asset>
     */
    public function ensureContainers(ServiceOrderTaskInstance $instance): Collection
    {
        $product = $instance->product;

        if (!$product?->bundle) {
            return collect();
        }

        $instance->loadMissing('serviceOrder');

        $existing = $this->containers($instance);
        $wanted = max(1, (int) ($instance->quantity ?? 1));

        if ($existing->count() >= $wanted) {
            return $existing;
        }

        for ($index = $existing->count(); $index < $wanted; $index++) {
            Asset::create([
                'customer_id' => $instance->serviceOrder->customer_id,
                'product_id' => $product->id,
                'service_order_task_instance_id' => $instance->id,
                'serial_number' => null,
                'date_in_service' => now()->toDateString(),
                'next_service_date' => now()->addDays($product->effectiveCertificateDays())->toDateString(),
            ]);
        }

        $instance->load('assets');

        return $this->containers($instance);
    }

    /**
     * Drops the bundle machines that a lowered aantal no longer sells. Only the empty ones
     * go — a container with parts under it stands for machines at the customer, which is
     * why lowering the aantal past it is refused rather than cleaned up.
     */
    public function pruneEmptyContainers(ServiceOrderTaskInstance $instance): void
    {
        if (!$instance->product?->bundle) {
            return;
        }

        $wanted = max(1, (int) ($instance->quantity ?? 1));

        $stale = $this->containers($instance)
            ->slice($wanted)
            ->filter(fn (Asset $container) => $this->childrenOf($instance, $container)->isEmpty());

        if ($stale->isEmpty()) {
            return;
        }

        $stale->each->delete();
        $instance->load('assets');
    }

    /**
     * How many bundles of this taak are already spoken for: everything up to and including
     * the last container that carries a part. The aantal can never drop below it.
     */
    public function usedContainerCount(ServiceOrderTaskInstance $instance): int
    {
        $containers = $this->containers($instance);

        for ($index = $containers->count() - 1; $index >= 0; $index--) {
            if ($this->childrenOf($instance, $containers[$index])->isNotEmpty()) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * @return Collection<int, Asset>
     */
    private function childrenOf(ServiceOrderTaskInstance $instance, Asset $container): Collection
    {
        return $instance->assets->where('parent_asset_id', $container->id);
    }
}
