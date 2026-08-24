<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tasks\TaskCancelled;
use App\Domain\Signals\Tasks\TaskCompletionChanged;
use App\Domain\Signals\Tasks\TaskSignatureRemoved;
use App\Domain\Signals\Tasks\TaskSigned;
use App\Http\Requests\ServiceOrderTaskInstanceCancelRequest;
use App\Http\Requests\ServiceOrderTaskInstanceDeleteRequest;
use App\Http\Requests\ServiceOrderTaskInstanceSignRequest;
use App\Http\Requests\ServiceOrderTaskInstanceStoreRequest;
use App\Http\Requests\ServiceOrderTaskInstanceToggleRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUnsignRequest;
use App\Http\Requests\ServiceOrderTaskInstanceUpdateRequest;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceBundleService;
use App\Services\TaskInstanceSerialSlotService;

class ServiceOrderTaskInstanceController extends Controller
{
    public function store(ServiceOrderTaskInstanceStoreRequest $request)
    {
        $data = $request->validated();
        $user_role_ids = $data['user_role_ids'] ?? [];
        $flex_parts = $data['flex_parts'] ?? [];
        unset($data['user_role_ids'], $data['flex_parts']);

        $instance = ServiceOrderTaskInstance::create($data);
        $instance->userRoles()->sync($user_role_ids);
        $this->syncFlexQuantities($instance, $flex_parts);

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }

    public function update(
        ServiceOrderTaskInstanceUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        TaskInstanceBundleService $bundles,
    ) {
        $data = $request->validated();

        if (array_key_exists('user_role_ids', $data)) {
            $serviceordertaskinstance->userRoles()->sync($data['user_role_ids']);
            unset($data['user_role_ids']);
        }

        $has_flex_parts = array_key_exists('flex_parts', $data);
        $flex_parts = $data['flex_parts'] ?? [];
        unset($data['flex_parts']);

        $serviceordertaskinstance->update($data);

        if ($has_flex_parts) {
            $this->syncFlexQuantities($serviceordertaskinstance, $flex_parts);
        }

        $serviceordertaskinstance->loadMissing(['product', 'assets']);
        $bundles->pruneEmptyContainers($serviceordertaskinstance);

        return redirect()->back()->with('success', 'Taak is bijgewerkt');
    }

    /**
     * The aantallen filled in for the bundle parts that leave theirs open, rewritten as a
     * whole. They are productables hanging off the taak instead of off a product: same
     * relation, different owner, because a flex part settles its number when it is sold.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $rows
     */
    private function syncFlexQuantities(ServiceOrderTaskInstance $instance, array $rows): void
    {
        $instance->productables()->delete();

        foreach ($rows as $row) {
            if ((int) $row['quantity'] < 1) {
                continue;
            }

            $instance->productables()->create([
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
            ]);
        }

        $instance->load('productables');
    }

    public function toggle(
        ServiceOrderTaskInstanceToggleRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        TaskInstanceSerialSlotService $slots,
    ) {
        $data = $request->validated();

        if ($data['is_complete'] && $serviceordertaskinstance->is_cancelled) {
            return redirect()->back()->withErrors([
                'task' => 'Geannuleerde taken kunnen niet worden voltooid.',
            ]);
        }

        if ($data['is_complete'] && $serviceordertaskinstance->product_id) {
            $serviceordertaskinstance->loadMissing([
                'product.brand',
                'product.productables.childProduct.brand',
                'productables',
                'assets',
            ]);

            if (!$slots->allSlotsFilled($serviceordertaskinstance)) {
                return redirect()->back()->withErrors([
                    'task' => 'Registreer eerst alle machines voordat je deze taak voltooit.',
                ]);
            }
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
            $update['completed_at'] = null;
            $update['completed_by'] = null;
            $update['signed_by'] = null;
            $update['signature_base64'] = null;
            $update['signed_at'] = null;
        }

        $serviceordertaskinstance->update($update);

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        Signals::dispatch(new TaskCompletionChanged(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            (bool) $serviceordertaskinstance->is_complete,
        ));

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
            'signed_by' => $data['signed_by'],
            'signature_base64' => $data['signature_base64'],
            'signed_at' => now(),
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        Signals::dispatch(new TaskSigned(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $serviceordertaskinstance->signed_by,
        ));

        return redirect()->back()->with('success', 'Taak ondertekend');
    }

    public function unsign(ServiceOrderTaskInstanceUnsignRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update([
            'signed_by' => null,
            'signature_base64' => null,
            'signed_at' => null,
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        Signals::dispatch(new TaskSignatureRemoved(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
        ));

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
            'is_cancelled' => true,
            'cancellation_reason' => $data['cancellation_reason'],
        ]);

        $serviceordertaskinstance->loadMissing('serviceOrder');

        $title = $serviceordertaskinstance->title
            ?? $serviceordertaskinstance->serviceOrderTask?->title
            ?? 'Taak';

        Signals::dispatch(new TaskCancelled(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $serviceordertaskinstance->cancellation_reason,
        ));

        return redirect()->back()->with('success', 'Taak geannuleerd');
    }
}
