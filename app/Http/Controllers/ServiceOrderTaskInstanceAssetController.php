<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tasks\TaskAssetSerialChanged;
use App\Domain\Signals\Tasks\TaskAssetsRegistered;
use App\Http\Requests\ServiceOrderTaskInstanceAssetStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceAssetUpdateRequest;
use App\Models\Asset;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceBundleService;
use Illuminate\Support\Facades\DB;

/**
 * The machines a werkbon task delivers, registered one at a time so a technician can walk
 * away half-way and pick the task back up later.
 *
 * A task for a plain product delivers those machines to the customer directly. A task for
 * a bundle delivers the bundle itself — one machine per sold bundle, carrying no serial —
 * with the parts hanging underneath it, so the customer ends up with the bundle rather
 * than a heap of loose parts.
 */
class ServiceOrderTaskInstanceAssetController extends Controller
{
    public function store(
        ServiceOrderTaskInstanceAssetStoreRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        TaskInstanceBundleService $bundles,
    ) {
        $rows = $request->validated()['assets'];
        $serviceordertaskinstance->loadMissing([
            'serviceOrder',
            'product.productables',
            'assets',
        ]);

        $products = Product::with('productType')
            ->whereIn('id', collect($rows)->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        $created = DB::transaction(function () use ($rows, $products, $serviceordertaskinstance, $bundles) {
            $containers = $bundles->ensureContainers($serviceordertaskinstance);
            $catalogue = $serviceordertaskinstance->product?->productables
                ->keyBy('productable_id') ?? collect();

            return collect($rows)->map(function (array $row) use (
                $products,
                $serviceordertaskinstance,
                $containers,
                $catalogue,
            ) {
                $product = $products[$row['product_id']];
                $container = $row['container_index'] === null
                    ? null
                    : $containers->get((int) $row['container_index']);

                /** @var ?Productable $part */
                $part = $container ? $catalogue->get($product->id) : null;

                return Asset::create([
                    'customer_id' => $container ? null : $serviceordertaskinstance->serviceOrder->customer_id,
                    'parent_asset_id' => $container?->id,
                    'productable_id' => $part?->id,
                    'product_relation_id' => $part?->product_relation_id,
                    'product_id' => $product->id,
                    'service_order_task_instance_id' => $serviceordertaskinstance->id,
                    'serial_number' => $this->serial($row['serial_number'] ?? null),
                    'date_in_service' => now()->toDateString(),
                    'next_service_date' => now()->addDays($product->effectiveCertificateDays())
                        ->toDateString(),
                ]);
            });
        });

        $title = $this->titleFor($serviceordertaskinstance);

        Signals::dispatch(new TaskAssetsRegistered(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $created->pluck('serial_number')->filter()->values()->all(),
        ));

        return redirect()->back()->with('success', $created->count() === 1
            ? 'Machine opgeslagen'
            : 'Machines opgeslagen');
    }

    public function update(
        ServiceOrderTaskInstanceAssetUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        Asset $asset,
    ) {
        $previous = $asset->serial_number;
        $asset->update(['serial_number' => $this->serial($request->validated()['serial_number'] ?? null)]);

        $serviceordertaskinstance->loadMissing('serviceOrder');
        $title = $this->titleFor($serviceordertaskinstance);

        Signals::dispatch(new TaskAssetSerialChanged(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $previous,
            $asset->serial_number,
        ));

        return redirect()->back()->with('success', 'Serienummer bijgewerkt');
    }

    private function serial(?string $serial): ?string
    {
        return trim((string) $serial) ?: null;
    }

    private function titleFor(ServiceOrderTaskInstance $instance): string
    {
        return $instance->title
            ?? $instance->serviceOrderTask?->title
            ?? 'Taak';
    }
}
