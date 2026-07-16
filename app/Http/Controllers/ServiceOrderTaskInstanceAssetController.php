<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderTaskInstanceAssetStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceAssetUpdateRequest;
use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Support\Facades\DB;

/**
 * The machines a werkbon task delivers, registered one serial at a time so a technician
 * can walk away half-way and pick the task back up later.
 */
class ServiceOrderTaskInstanceAssetController extends Controller
{
    public function store(
        ServiceOrderTaskInstanceAssetStoreRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
    ) {
        $rows = $request->validated()['assets'];
        $serviceordertaskinstance->loadMissing('serviceOrder');
        $customer_id = $serviceordertaskinstance->serviceOrder->customer_id;
        $today = now()->toDateString();

        $products = Product::with('productType')
            ->whereIn('id', collect($rows)->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        $created = DB::transaction(function () use ($rows, $products, $serviceordertaskinstance, $customer_id, $today) {
            return collect($rows)->map(function (array $row) use ($products, $serviceordertaskinstance, $customer_id, $today) {
                $product = $products[$row['product_id']];

                return Asset::create([
                    'customer_id' => $customer_id,
                    'product_id' => $product->id,
                    'service_order_task_instance_id' => $serviceordertaskinstance->id,
                    'serial_number' => trim($row['serial_number']),
                    'date_in_service' => $today,
                    'next_service_date' => now()->addDays($product->effectiveCertificateDays())
                        ->toDateString(),
                ]);
            });
        });

        $title = $this->titleFor($serviceordertaskinstance);
        $serials = $created->pluck('serial_number')->implode(', ');

        $serviceordertaskinstance->serviceOrder->logActivity(
            'Apparatuur geregistreerd bij taak "' . $title . '": ' . $serials,
            category: 'status',
        );

        return redirect()->back()->with('success', $created->count() === 1
            ? 'Serienummer opgeslagen'
            : 'Serienummers opgeslagen');
    }

    public function update(
        ServiceOrderTaskInstanceAssetUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        Asset $asset,
    ) {
        $previous = $asset->serial_number;
        $asset->update(['serial_number' => trim($request->validated()['serial_number'])]);

        $serviceordertaskinstance->loadMissing('serviceOrder');
        $title = $this->titleFor($serviceordertaskinstance);

        $serviceordertaskinstance->serviceOrder->logActivity(
            'Serienummer bij taak "' . $title . '" gewijzigd van ' . $previous . ' naar ' . $asset->serial_number,
            category: 'status',
        );

        return redirect()->back()->with('success', 'Serienummer bijgewerkt');
    }

    private function titleFor(ServiceOrderTaskInstance $instance): string
    {
        return $instance->title
            ?? $instance->serviceOrderTask?->title
            ?? 'Taak';
    }
}
