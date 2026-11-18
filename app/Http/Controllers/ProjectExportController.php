<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectExportRequest;
use App\Models\Project;
use App\Services\ProjectExportService;
use App\Support\Spreadsheets\XlsxDownload;
use Illuminate\Support\Str;

class ProjectExportController extends Controller
{
    public function __invoke(ProjectExportRequest $request, Project $project, ProjectExportService $service)
    {
        $spreadsheet = $service->build(
            $project,
            $request->user(),
            $request->validated('tz') ?: config('app.display_timezone'),
        );

        $filename = 'project-' . $project->id . '-' . (Str::slug($project->title) ?: 'export') . '.xlsx';

        return XlsxDownload::response($spreadsheet, $filename);
    }
}
