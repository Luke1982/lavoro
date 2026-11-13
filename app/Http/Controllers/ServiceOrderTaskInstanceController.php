<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\CreateTaskInstanceAction;
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
use App\Services\TaskChecks\TaskCompletionGuard;
use App\Services\TaskChecks\TaskInstanceChecks;
use App\Services\TaskInstanceBundleService;
use Illuminate\Support\Facades\DB;

class ServiceOrderTaskInstanceController extends Controller
{
    public function store(ServiceOrderTaskInstanceStoreRequest $request, CreateTaskInstanceAction $create)
    {
        DB::transaction(function () use ($request, $create) {
            $instance = $create->execute($request->taskColumns(), $request->extraChecks());
            $instance->userRoles()->sync($request->validated('user_role_ids', []));
            $this->syncFlexQuantities($instance, $request->validated('flex_parts', []));
        });

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }

    public function update(
        ServiceOrderTaskInstanceUpdateRequest $request,
        ServiceOrderTaskInstance $serviceordertaskinstance,
        TaskInstanceBundleService $bundles,
        TaskInstanceChecks $checks,
    ) {
        $instance = $serviceordertaskinstance;

        DB::transaction(function () use ($request, $instance, $bundles, $checks) {
            $was_per_unit = (bool) $instance->checks_per_unit;

            if ($request->has('user_role_ids')) {
                $instance->userRoles()->sync($request->validated('user_role_ids'));
            }

            $instance->update($request->taskColumns());
            $instance->unsetRelation('product');

            $checks->followEdit($instance, $was_per_unit, $request->extraChecks());

            if ($request->has('flex_parts')) {
                $this->syncFlexQuantities($instance, $request->validated('flex_parts', []));
            }

            $instance->load(['product', 'assets']);
            $bundles->pruneEmptyContainers($instance);
        });

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
        TaskCompletionGuard $guard,
    ) {
        $data = $request->validated();

        if ($data['is_complete'] && $serviceordertaskinstance->is_cancelled) {
            return redirect()->back()->withErrors([
                'task' => 'Geannuleerde taken kunnen niet worden voltooid.',
            ]);
        }

        if ($data['is_complete']) {
            $problem = $guard->problem($serviceordertaskinstance);

            if ($problem !== null) {
                return redirect()->back()->withErrors(['task' => $problem]);
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

        $title = $serviceordertaskinstance->timelineTitle();

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

        $title = $serviceordertaskinstance->timelineTitle();

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

        $title = $serviceordertaskinstance->timelineTitle();

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

        $title = $serviceordertaskinstance->timelineTitle();

        Signals::dispatch(new TaskCancelled(
            $serviceordertaskinstance->serviceOrder,
            $serviceordertaskinstance,
            $title,
            $serviceordertaskinstance->cancellation_reason,
        ));

        return redirect()->back()->with('success', 'Taak geannuleerd');
    }
}
