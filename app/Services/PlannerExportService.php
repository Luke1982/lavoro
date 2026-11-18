<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Support\Spreadsheets\AppointmentHours;
use App\Support\Spreadsheets\SheetWriter;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PlannerExportService
{
    private const COLUMNS = [
        'Afspraak',
        'Werkbon',
        'Klant',
        'Locatie',
        'Project',
        ...AppointmentHours::COLUMNS,
    ];

    public function __construct(private EventLocationResolver $location_resolver) {}

    public function build(array $user_ids, string $start, string $end, string $timezone): Spreadsheet
    {
        $range_start = Carbon::parse($start . ' 00:00:00', $timezone)->utc();
        $range_end = Carbon::parse($end . ' 23:59:59', $timezone)->utc();

        $users = User::whereIn('id', $user_ids)->orderBy('name')->get();

        $events = Event::query()
            ->where('start', '<=', $range_end)
            ->where('end', '>=', $range_start)
            ->whereHas('executingUsers', fn ($q) => $q->whereIn('users.id', $user_ids))
            ->with([
                'eventType',
                'executingUsers',
                'executions',
                ...EventLocationResolver::relations(),
            ])
            ->orderBy('start')
            ->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $used_titles = [];

        foreach ($users as $index => $user) {
            $title = $this->sheetTitle($user->name, $index, $used_titles);
            $sheet = $spreadsheet->addSheet(new Worksheet($spreadsheet, $title));

            $rows = [];
            foreach ($events as $event) {
                $member = $event->executingUsers->firstWhere('id', $user->id);
                if ($member) {
                    $rows[] = $this->row($event, $member, $timezone);
                }
            }

            (new SheetWriter($sheet, $timezone))->table(self::COLUMNS, $rows);
        }

        return $spreadsheet;
    }

    private function row(Event $event, User $member, string $timezone): array
    {
        $service_order = $event->serviceOrders->first();

        return [
            'Afspraak' => $event->name ?: $event->eventType?->name,
            'Werkbon' => $service_order?->number,
            'Klant' => $service_order?->customer?->name,
            'Locatie' => $this->location_resolver->resolve($event),
            'Project' => $service_order?->project?->title,
            ...AppointmentHours::for($event, $member, $timezone)->row(),
        ];
    }

    private function sheetTitle(string $name, int $index, array &$used_titles): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $name);
        $clean = trim($clean);
        $clean = $clean === '' ? ('Monteur ' . ($index + 1)) : $clean;
        $clean = mb_substr($clean, 0, 31);

        $candidate = $clean;
        $suffix = 1;
        while (in_array($candidate, $used_titles, true)) {
            $candidate = mb_substr($clean, 0, 28) . ' ' . (++$suffix);
        }

        $used_titles[] = $candidate;

        return $candidate;
    }
}
