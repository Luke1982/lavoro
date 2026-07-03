<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use App\Http\Requests\ServiceOrderTaskInstanceStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUpdateRequest;
use App\Http\Requests\ServiceOrderTaskInstanceToggleRequest;
use App\Http\Requests\ServiceOrderTaskInstanceDeleteRequest;
use App\Http\Requests\ServiceOrderTaskInstanceSignRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUnsignRequest;
use App\Http\Requests\ServiceOrderTaskInstanceCancelRequest;

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

        if ($data['is_complete'] && $serviceordertaskinstance->is_cancelled) {
            return redirect()->back()->withErrors([
                'task' => 'Geannuleerde taken kunnen niet worden voltooid.',
            ]);
        }

        if (!$data['is_complete'] && $serviceordertaskinstance->product_id) {
            if ($serviceordertaskinstance->assets()->exists()) {
                return redirect()->back()->withErrors([
                    'task' => 'Deze taak heeft apparatuur geregistreerd en kan niet meer heropend worden.',
                ]);
            }
        }

        $update = ['is_complete' => $data['is_complete']];

        if ($data['is_complete']) {
            $update['completed_at'] = now();
            $update['completed_by'] = auth()->id();
        } else {
            $update['completed_at']     = null;
            $update['completed_by']     = null;
            $update['signed_by']        = null;
            $update['signature_base64'] = null;
            $update['signed_at']        = null;
        }

        $serviceordertaskinstance->update($update);

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

    public function sign(ServiceOrderTaskInstanceSignRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        if (!$serviceordertaskinstance->is_complete) {
            return redirect()->back()->withErrors([
                'sign' => 'Alleen voltooide taken kunnen ondertekend worden.',
            ]);
        }

        $data = $request->validated();

        $serviceordertaskinstance->update([
            'signed_by'        => $data['signed_by'],
            'signature_base64' => $data['signature_base64'],
            'signed_at'        => now(),
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        $message = 'Taak "' . $title . '" ondertekend door ' . $data['signed_by'];
        $serviceordertaskinstance->serviceOrder->logActivity(
            $message,
            category: 'status',
        );

        return redirect()->back()->with('success', 'Taak ondertekend');
    }

    public function unsign(ServiceOrderTaskInstanceUnsignRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update([
            'signed_by'        => null,
            'signature_base64' => null,
            'signed_at'        => null,
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        $message = 'Handtekening van taak "' . $title . '" verwijderd';
        $serviceordertaskinstance->serviceOrder->logActivity(
            $message,
            category: 'status',
        );

        return redirect()->back()->with('success', 'Handtekening verwijderd');
    }

    public function cancel(ServiceOrderTaskInstanceCancelRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        if ($serviceordertaskinstance->is_complete) {
            return redirect()->back()->withErrors([
                'task' => 'Voltooide taken kunnen niet worden geannuleerd.',
            ]);
        }

        $data = $request->validated();

        $serviceordertaskinstance->update([
            'is_cancelled'        => true,
            'cancellation_reason' => $data['cancellation_reason'],
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title   = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';
        $message = 'Taak "' . $title . '" geannuleerd: ' . $data['cancellation_reason'];

        $serviceordertaskinstance->serviceOrder->logActivity($message, category: 'status');

        return redirect()->back()->with('success', 'Taak geannuleerd');
    }
}
