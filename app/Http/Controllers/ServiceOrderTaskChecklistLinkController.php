<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderTaskChecklistLinkReorderRequest;
use App\Http\Requests\ServiceOrderTaskChecklistLinkStoreRequest;
use App\Http\Requests\ServiceOrderTaskLinkDeleteRequest;
use App\Http\Requests\ServiceOrderTaskLinkUpdateRequest;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskChecklistLink;

/**
 * A checklist placed on a task: placed required and last, made optional or
 * required for that task, taken off, or moved among the task's checklists.
 */
class ServiceOrderTaskChecklistLinkController extends Controller
{
    public function store(ServiceOrderTaskChecklistLinkStoreRequest $request)
    {
        $task_id = $request->integer('task_id');

        ServiceOrderTaskChecklistLink::create([
            'service_order_task_checklist_id' => $request->integer('checklist_id'),
            'checklistable_type' => ServiceOrderTask::class,
            'checklistable_id' => $task_id,
            'is_required' => true,
            'sort_order' => ServiceOrderTaskChecklistLink::nextSortOrder(ServiceOrderTask::class, $task_id),
        ]);

        return redirect()->back();
    }

    public function update(ServiceOrderTaskLinkUpdateRequest $request, ServiceOrderTaskChecklistLink $link)
    {
        $link->update(['is_required' => $request->boolean('is_required')]);

        return redirect()->back();
    }

    public function destroy(ServiceOrderTaskLinkDeleteRequest $request, ServiceOrderTaskChecklistLink $link)
    {
        $link->delete();

        return redirect()->back();
    }

    public function reorder(ServiceOrderTaskChecklistLinkReorderRequest $request)
    {
        ServiceOrderTaskChecklistLink::putInOrder($request->input('ids'));

        return redirect()->back();
    }
}
