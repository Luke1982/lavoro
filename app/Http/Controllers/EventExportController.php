<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventExportRequest;
use App\Services\PlannerExportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EventExportController extends Controller
{
    public function __invoke(EventExportRequest $request, PlannerExportService $service)
    {
        $timezone = $request->validated('tz') ?: 'Europe/Amsterdam';

        $spreadsheet = $service->build(
            array_map('intval', $request->validated('user_ids')),
            $request->validated('start'),
            $request->validated('end'),
            $timezone,
        );

        $writer = new Xlsx($spreadsheet);

        $filename = 'planning-export-' . $request->validated('start') . '-' . $request->validated('end') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
