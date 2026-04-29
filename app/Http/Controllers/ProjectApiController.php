<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectReadRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Carbon\Carbon;

class ProjectApiController extends Controller
{
    public function index(ProjectReadRequest $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        $projects = Project::query()
            ->whereNotNull('start_date', 'and')
            ->whereNotNull('end_date', 'and')
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->where('start_date', '<=', $end)
                    ->where('end_date', '>=', $start);
            })
            ->with('customer:id,name')
            ->get(['id', 'title', 'start_date', 'end_date', 'status', 'customer_id']);

        $events = $projects->map(function (Project $project) {
            return [
                'id' => 'project-' . $project->id,
                'title' => $project->title,
                'start' => Carbon::parse($project->start_date)->toDateString(),
                'end' => Carbon::parse($project->end_date)->addDay()->toDateString(),
                'allDay' => true,
                'editable' => false,
                'color' => '#2563eb',
                'extendedProps' => [
                    'kind' => 'project',
                    'project_id' => $project->id,
                    'status' => $project->status,
                    'customer_name' => $project->customer?->name,
                ],
            ];
        });

        return response()->json($events->values());
    }

    public function milestones(ProjectReadRequest $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        $milestones = ProjectMilestone::query()
            ->where(function ($q) {
                $q->whereNotNull('actual_date', 'and')->orWhereNotNull('projected_date');
            })
            ->when($start && $end, function ($q) use ($start, $end) {
                $start_date = Carbon::parse($start)->toDateString();
                $end_date = Carbon::parse($end)->toDateString();
                $q->whereRaw('COALESCE(actual_date, projected_date) BETWEEN ? AND ?', [
                    $start_date,
                    $end_date,
                ]);
            })
            ->with('project:id,title')
            ->get([
                'id',
                'project_id',
                'title',
                'projected_date',
                'actual_date',
            ]);

        $events = $milestones->map(function (ProjectMilestone $milestone) {
            $effective_date = $milestone->actual_date ?? $milestone->projected_date;
            $is_actual = (bool) $milestone->actual_date;

            return [
                'id' => 'milestone-' . $milestone->id,
                'title' => $milestone->title,
                'start' => Carbon::parse($effective_date)->toDateString(),
                'allDay' => true,
                'editable' => false,
                'color' => $is_actual ? '#059669' : '#d97706',
                'extendedProps' => [
                    'kind' => 'milestone',
                    'project_id' => $milestone->project_id,
                    'project_title' => $milestone->project?->title,
                    'is_actual' => $is_actual,
                ],
            ];
        });

        return response()->json($events->values());
    }
}
