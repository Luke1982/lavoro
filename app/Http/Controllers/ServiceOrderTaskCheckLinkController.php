<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderTaskCheckLinkReorderRequest;
use App\Http\Requests\ServiceOrderTaskCheckLinkStoreRequest;
use App\Http\Requests\ServiceOrderTaskLinkDeleteRequest;
use App\Http\Requests\ServiceOrderTaskLinkUpdateRequest;
use App\Models\ServiceOrderTaskCheckLink;

/**
 * A check placed on a checklist or a task: placed required and last, made
 * optional or required, taken off, or moved within its list.
 */
class ServiceOrderTaskCheckLinkController extends Controller
{
    public function store(ServiceOrderTaskCheckLinkStoreRequest $request)
    {
        $class = $request->targetClass();
        $target_id = $request->integer('target_id');

        ServiceOrderTaskCheckLink::create([
            'service_order_task_check_id' => $request->integer('check_id'),
            'checkable_type' => $class,
            'checkable_id' => $target_id,
            'is_required' => true,
            'sort_order' => ServiceOrderTaskCheckLink::nextSortOrder($class, $target_id),
        ]);

        return redirect()->back();
    }

    public function update(ServiceOrderTaskLinkUpdateRequest $request, ServiceOrderTaskCheckLink $link)
    {
        $link->update(['is_required' => $request->boolean('is_required')]);

        return redirect()->back();
    }

    public function destroy(ServiceOrderTaskLinkDeleteRequest $request, ServiceOrderTaskCheckLink $link)
    {
        $link->delete();

        return redirect()->back();
    }

    public function reorder(ServiceOrderTaskCheckLinkReorderRequest $request)
    {
        ServiceOrderTaskCheckLink::putInOrder($request->input('ids'));

        return redirect()->back();
    }
}
