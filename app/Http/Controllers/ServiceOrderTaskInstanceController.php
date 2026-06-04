<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use App\Http\Requests\ServiceOrderTaskInstanceStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUpdateRequest;
use App\Http\Requests\ServiceOrderTaskInstanceToggleRequest;
use App\Http\Requests\ServiceOrderTaskInstanceDeleteRequest;

class ServiceOrderTaskInstanceController extends Controller
{
    public function store(ServiceOrderTaskInstanceStoreRequest $request)
    {
        ServiceOrderTaskInstance::create($request->validated());

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }

    public function update(ServiceOrderTaskInstanceUpdateRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update($request->validated());

        return redirect()->back()->with('success', 'Taak is bijgewerkt');
    }

    public function toggle(ServiceOrderTaskInstanceToggleRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $data = $request->validated();

        if (!$data['is_complete'] && $serviceordertaskinstance->product_id) {
            if ($serviceordertaskinstance->assets()->exists()) {
                return redirect()->back()->withErrors([
                    'task' => 'Deze taak heeft apparatuur geregistreerd en kan niet meer heropend worden.',
                ]);
            }
        }

        $serviceordertaskinstance->update(['is_complete' => $data['is_complete']]);

        if ($data['is_complete'] && !empty($data['assets'])) {
            $serviceordertaskinstance->loadMissing('serviceOrder');
            $customer_id = $serviceordertaskinstance->serviceOrder->customer_id;
            $today       = now()->toDateString();

            foreach ($data['assets'] as $asset_data) {
                $product = Product::with('productType')->find($asset_data['product_id']);
                if (!$product) {
                    continue;
                }

                Asset::create([
                    'customer_id'                    => $customer_id,
                    'product_id'                     => $product->id,
                    'service_order_task_instance_id' => $serviceordertaskinstance->id,
                    'serial_number'                  => $asset_data['serial_number'],
                    'date_in_service'                => $today,
                    'next_service_date'              => now()->addDays($product->effectiveCertificateDays())
                                                         ->toDateString(),
                ]);
            }
        }

        $title  = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';
        $action = $data['is_complete'] ? 'voltooid' : 'heropend';

        $serviceordertaskinstance->serviceOrder->logActivity(
            "Taak \"{$title}\" {$action}",
            category: 'status',
        );

        return redirect()->back()->with('success', 'Taakstatus bijgewerkt');
    }

    public function destroy(ServiceOrderTaskInstanceDeleteRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->delete();

        return redirect()->back()->with('success', 'Taak is verwijderd');
    }
}
