<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklist;
use Illuminate\Support\Collection;

/**
 * Which checks come out of the library: the ones a task template hands a new
 * werkbon task, and the ones picked on top of it.
 */
class TaskCheckCatalogue
{
    /**
     * The template's checklists in their order, each with its checks in order, then
     * the template's loose checks. A check reached more than once comes once, where
     * it first appears, and is required when any route to it is. A route through a
     * checklist is required only when both the checklist's link to the task and the
     * check's link inside the list are.
     *
     * @return Collection<int, CheckItem>
     */
    public function forTemplate(?ServiceOrderTask $task): Collection
    {
        if (!$task) {
            return collect();
        }

        $task->loadMissing(['checklists.checks', 'checks']);

        $routes = $task->checklists->toBase()
            ->flatMap(fn (ServiceOrderTaskChecklist $list) => $list->checks->map(fn (ServiceOrderTaskCheck $check) => CheckItem::fromLibrary(
                $check,
                $list,
                (bool) $list->pivot->is_required && (bool) $check->pivot->is_required,
            )))
            ->concat($task->checks->toBase()->map(fn (ServiceOrderTaskCheck $check) => CheckItem::fromLibrary(
                $check,
                null,
                (bool) $check->pivot->is_required,
            )));

        return $routes
            ->groupBy(fn (CheckItem $item) => $item->check_id)
            ->map(fn (Collection $same) => $same->first()->withRequired($same->contains('is_required', true)))
            ->values();
    }

    /**
     * Checks and whole checklists picked from the library, and titles typed for one
     * werkbon, in the order given. Always optional.
     *
     * @return Collection<int, CheckItem>
     */
    public function picked(PickedExtras $extras): Collection
    {

        $checks = ServiceOrderTaskCheck::findMany($extras->check_ids)->toBase()
            ->sortBy(fn (ServiceOrderTaskCheck $check) => array_search($check->id, $extras->check_ids))
            ->map(fn (ServiceOrderTaskCheck $check) => CheckItem::fromLibrary($check, null, false, true));

        $listed = ServiceOrderTaskChecklist::with('checks')->findMany($extras->checklist_ids)->toBase()
            ->sortBy(fn (ServiceOrderTaskChecklist $list) => array_search($list->id, $extras->checklist_ids))
            ->flatMap(fn (ServiceOrderTaskChecklist $list) => $list->checks
                ->map(fn (ServiceOrderTaskCheck $check) => CheckItem::fromLibrary($check, $list, false, true)));

        $typed = collect($extras->titles)
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique()
            ->map(fn (string $title) => CheckItem::typed($title));

        return $checks->concat($listed)->concat($typed)
            ->unique(fn (CheckItem $item) => $item->check_id ?? 'title:' . $item->title)
            ->values();
    }
}
