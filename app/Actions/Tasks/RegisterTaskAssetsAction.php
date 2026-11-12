<?php

namespace App\Actions\Tasks;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tasks\TaskAssetsRegistered;
use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceBundleService;
use App\Services\TaskInstanceSerialSlotService;
use App\Support\Placeholder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registers the machines a werkbon taak delivers, at the customer, on the taak.
 *
 * The mechanic does this from the serial drawer once the machines hang on the
 * wall. The assistant does it earlier, when a signed offerte is turned into a
 * werkbon: the machines are sold, so they exist as placeholders the mechanic
 * only has to give their real serials. Both go through here, so a machine
 * registered ahead of time sits in exactly the slot the drawer would have put
 * it in, and nobody registers the same airco twice.
 */
class RegisterTaskAssetsAction
{
    public function __construct(
        private readonly TaskInstanceBundleService $bundles,
        private readonly TaskInstanceSerialSlotService $slots,
    ) {}

    /**
     * @param  array<int, array{product_id: int, serial_number: ?string, container_index: ?int}>  $rows
     * @param  bool  $in_service  False for a machine that is sold but not yet hung: it gets its
     *                            dates when the taak is completed (PutDeliveredMachinesIntoService).
     * @return Collection<int, Asset>
     */
    public function execute(ServiceOrderTaskInstance $instance, array $rows, bool $in_service = true): Collection
    {
        $instance->loadMissing(['serviceOrder', 'product.productables', 'assets']);

        $products = Product::with('productType')
            ->whereIn('id', collect($rows)->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($rows, $products, $instance, $in_service) {
            $containers = $this->bundles->ensureContainers($instance);
            $catalogue = $instance->product?->productables->keyBy('productable_id') ?? collect();

            $created = collect($rows)->map(function (array $row) use (
                $products,
                $instance,
                $containers,
                $catalogue,
                $in_service,
            ) {
                $product = $products[$row['product_id']];
                $container = $row['container_index'] === null
                    ? null
                    : $containers->get((int) $row['container_index']);

                $part = $container ? $catalogue->get($product->id) : null;

                return Asset::create([
                    'customer_id' => $container ? null : $instance->serviceOrder->customer_id,
                    'parent_asset_id' => $container?->id,
                    'productable_id' => $part?->id,
                    'product_relation_id' => $part?->product_relation_id,
                    'product_id' => $product->id,
                    'service_order_task_instance_id' => $instance->id,
                    'serial_number' => trim((string) ($row['serial_number'] ?? '')) ?: null,
                    ...($in_service ? self::serviceDatesFor($product) : []),
                ]);
            });

            Signals::dispatch(new TaskAssetsRegistered(
                $instance->serviceOrder,
                $instance,
                self::titleFor($instance),
                $created->pluck('serial_number')->filter()->values()->all(),
            ));

            return $created;
        });
    }

    /**
     * The dates a machine gets on the day it goes into service.
     *
     * @return array{date_in_service: string, next_service_date: string}
     */
    public static function serviceDatesFor(Product $product): array
    {
        return [
            'date_in_service' => now()->toDateString(),
            'next_service_date' => now()->addDays($product->effectiveCertificateDays())->toDateString(),
        ];
    }

    /**
     * One row for every slot of this taak still waiting for a machine, each with a
     * placeholder serial where the product carries one — numbered, because a serial
     * is unique per product and a taak can deliver several of the same.
     *
     * @return array<int, array{product_id: int, serial_number: ?string, container_index: ?int}>
     */
    public function placeholderRowsFor(ServiceOrderTaskInstance $instance): array
    {
        $instance->loadMissing([
            'product.brand',
            'product.productables.childProduct.brand',
            'productables',
            'assets',
        ]);

        $rows = [];
        $handed_out = [];

        foreach ($this->slots->groups($instance) as $group) {
            for ($missing = $group['expected'] - count($group['assets']); $missing > 0; $missing--) {
                $serial = null;

                if ($group['requires_serial']) {
                    $serial = Placeholder::serialFor($group['product_id'], $handed_out[$group['product_id']] ?? []);
                    $handed_out[$group['product_id']][] = $serial;
                }

                $rows[] = [
                    'product_id' => $group['product_id'],
                    'serial_number' => $serial,
                    'container_index' => $group['container_index'],
                ];
            }
        }

        return $rows;
    }

    public static function titleFor(ServiceOrderTaskInstance $instance): string
    {
        return $instance->title
            ?? $instance->serviceOrderTask?->title
            ?? 'Taak';
    }
}
