<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PlannerExportService
{
    private const HEADERS = [
        'Afspraak',
        'Werkbon',
        'Klant',
        'Locatie',
        'Project',
        'Geplande start',
        'Geplande eind',
        'Geplande uren',
        'Werkelijke start',
        'Werkelijke eind',
        'Werkelijke uren',
        'Pauze',
        'Status',
    ];

    private const DATETIME_FORMAT = 'yyyy-mm-dd hh:mm';

    public function build(array $user_ids, string $start, string $end, string $timezone): Spreadsheet
    {
        $tz = $timezone;

        $range_start = Carbon::parse($start . ' 00:00:00', $tz)->utc();
        $range_end = Carbon::parse($end . ' 23:59:59', $tz)->utc();

        $users = User::whereIn('id', $user_ids)->orderBy('name')->get();

        $events = Event::query()
            ->where('start', '<=', $range_end)
            ->where('end', '>=', $range_start)
            ->whereHas('executingUsers', fn ($q) => $q->whereIn('users.id', $user_ids))
            ->with([
                'eventType',
                'executingUsers',
                'executions',
                'serviceOrders.customer',
                'serviceOrders.project',
            ])
            ->orderBy('start')
            ->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $used_titles = [];

        foreach ($users as $index => $user) {
            $sheet = new Worksheet($spreadsheet, $this->sheetTitle($user->name, $index, $used_titles));
            $spreadsheet->addSheet($sheet);

            $sheet->fromArray([self::HEADERS], null, 'A1');
            $sheet->getStyle('A1:M1')->getFont()->setBold(true);

            $row = 2;
            foreach ($events as $event) {
                $member = $event->executingUsers->firstWhere('id', $user->id);
                if (!$member) {
                    continue;
                }

                $this->writeRow($sheet, $row, $event, $user, $member, $tz);
                $row++;
            }

            foreach (range('A', 'M') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        return $spreadsheet;
    }

    private function writeRow(Worksheet $sheet, int $row, Event $event, User $user, User $member, string $tz): void
    {
        $service_order = $event->serviceOrders->first();
        $project = $service_order?->project;
        $customer = $service_order?->customer;
        $execution = $event->executions->firstWhere('user_id', $user->id);
        $breaktime_minutes = (int) ($member->pivot->breaktime ?? 0);

        $sheet->setCellValue('A' . $row, $event->name ?: $event->eventType?->name);
        $sheet->setCellValue('B' . $row, $service_order ? 'WB-' . str_pad((string) $service_order->id, 4, '0', STR_PAD_LEFT) : null);
        $sheet->setCellValue('C' . $row, $customer?->name);
        $sheet->setCellValue('D' . $row, $this->location($event, $service_order, $project, $customer));
        $sheet->setCellValue('E' . $row, $project?->title);

        [$planned_start, $planned_end] = $this->plannedTimes($event, $member);
        $this->writeDateTime($sheet, 'F' . $row, $planned_start, $tz);
        $this->writeDateTime($sheet, 'G' . $row, $planned_end, $tz);
        $sheet->setCellValue('H' . $row, $this->hours($planned_start, $planned_end, $breaktime_minutes));
        $this->writeDateTime($sheet, 'I' . $row, $execution?->actual_start, $tz);
        $this->writeDateTime($sheet, 'J' . $row, $execution?->actual_end, $tz);
        $sheet->setCellValue(
            'K' . $row,
            $this->hours($execution?->actual_start, $execution?->actual_end, $breaktime_minutes)
        );

        $sheet->setCellValue('L' . $row, $breaktime_minutes);
        $sheet->setCellValue('M' . $row, $execution?->completion_status ?? 'Gepland');
    }

    private function hours(?Carbon $start, ?Carbon $end, int $breaktime_minutes): ?float
    {
        if (!$start || !$end) {
            return null;
        }

        return round($start->floatDiffInHours($end) - ($breaktime_minutes / 60), 2);
    }

    private function plannedTimes(Event $event, User $member): array
    {
        $pivot = $member->pivot;

        if ($pivot && $pivot->has_diverging_times && $pivot->diverging_start && $pivot->diverging_end) {
            $date = $event->start->format('Y-m-d');

            return [
                Carbon::parse($date . ' ' . $pivot->diverging_start),
                Carbon::parse($date . ' ' . $pivot->diverging_end),
            ];
        }

        return [$event->start, $event->end];
    }

    private function writeDateTime(Worksheet $sheet, string $cell, ?Carbon $value, string $tz): void
    {
        if (!$value) {
            return;
        }

        $local = $value->copy()->setTimezone($tz);
        $serial = ExcelDate::formattedPHPToExcel(
            $local->year,
            $local->month,
            $local->day,
            $local->hour,
            $local->minute,
            $local->second,
        );

        $sheet->setCellValue($cell, $serial);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATETIME_FORMAT);
    }

    private function location(Event $event, $service_order, $project, $customer): ?string
    {
        if ($event->location) {
            return $event->location;
        }

        if ($service_order?->resolved_location) {
            return $service_order->resolved_location;
        }

        if ($project?->location) {
            return $project->location;
        }

        if ($customer) {
            return collect([$customer->address, $customer->city])->filter()->implode(', ') ?: null;
        }

        return null;
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
