<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventExportRequest;
use App\Services\PlannerExportService;
use App\Support\Spreadsheets\XlsxDownload;

class EventExportController extends Controller
{
    public function __invoke(EventExportRequest $request, PlannerExportService $service)
    {
        $spreadsheet = $service->build(
            array_map('intval', $request->validated('user_ids')),
            $request->validated('start'),
            $request->validated('end'),
            $request->validated('tz') ?: config('app.display_timezone'),
        );

        $filename = 'planning-export-' . $request->validated('start') . '-' . $request->validated('end') . '.xlsx';

        return XlsxDownload::response($spreadsheet, $filename);
    }
}
