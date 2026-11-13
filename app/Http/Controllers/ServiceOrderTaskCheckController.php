<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderTaskCheckDeleteRequest;
use App\Http\Requests\ServiceOrderTaskCheckReadRequest;
use App\Http\Requests\ServiceOrderTaskCheckStoreUpdateRequest;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskCheckLink;
use App\Models\ServiceOrderTaskChecklist;
use App\Models\ServiceOrderTaskChecklistLink;

/**
 * The checklist editor: the library of checks, the checklists and the task
 * templates side by side, with every link between them.
 */
class ServiceOrderTaskCheckController extends Controller
{
    public function index(ServiceOrderTaskCheckReadRequest $request)
    {
        $check_link = fn (ServiceOrderTaskCheckLink $link) => [
            'id' => $link->id,
            'check_id' => $link->service_order_task_check_id,
            'is_required' => $link->is_required,
            'sort_order' => $link->sort_order,
        ];

        return inertia('ServiceOrderTaskChecks/EditorPage', [
            'checks' => ServiceOrderTaskCheck::orderBy('title')->get(['id', 'title']),
            'checklists' => ServiceOrderTaskChecklist::with('checkLinks')->orderBy('title')->get()
                ->map(fn (ServiceOrderTaskChecklist $list) => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'links' => $list->checkLinks->map($check_link),
                ]),
            'tasks' => ServiceOrderTask::with(['checkLinks', 'checklistLinks'])->orderBy('title')->get()
                ->map(fn (ServiceOrderTask $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'check_links' => $task->checkLinks->map($check_link),
                    'checklist_links' => $task->checklistLinks
                        ->map(fn (ServiceOrderTaskChecklistLink $link) => [
                            'id' => $link->id,
                            'checklist_id' => $link->service_order_task_checklist_id,
                            'is_required' => $link->is_required,
                            'sort_order' => $link->sort_order,
                        ]),
                ]),
        ]);
    }

    public function store(ServiceOrderTaskCheckStoreUpdateRequest $request)
    {
        ServiceOrderTaskCheck::create($request->validated());

        return redirect()->back()->with('success', 'Check is aangemaakt');
    }

    public function update(ServiceOrderTaskCheckStoreUpdateRequest $request, ServiceOrderTaskCheck $serviceordertaskcheck)
    {
        $serviceordertaskcheck->update($request->validated());

        return redirect()->back()->with('success', 'Check is bijgewerkt');
    }

    /**
     * Its links go with it; werkbon tasks keep their own copy of the check.
     */
    public function destroy(ServiceOrderTaskCheckDeleteRequest $request, ServiceOrderTaskCheck $serviceordertaskcheck)
    {
        $serviceordertaskcheck->delete();

        return redirect()->back()->with('success', 'Check is verwijderd');
    }
}
