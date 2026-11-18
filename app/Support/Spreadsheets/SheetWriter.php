<?php

namespace App\Support\Spreadsheets;

use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills a worksheet from top to bottom with tables and blocks of facts, so every
 * export shows a date as a date Excel can sort and a euro as a number it can add.
 *
 * A row holds its values by header. A column is given as its header, or as its
 * header with a number format; a header a row has no value for stays empty, so a
 * column can be left out for one viewer without touching the rows.
 *
 * Text is always written as text: a werkbon description that starts with "="
 * must not turn into a formula in somebody's Excel.
 */
final class SheetWriter
{
    public const DATETIME = 'yyyy-mm-dd hh:mm';

    public const DATE = 'yyyy-mm-dd';

    public const EURO = '"€" #,##0.00';

    public const HOURS = '0.00';

    public const PERCENT = '0.0%';

    private int $row = 1;

    public function __construct(
        private Worksheet $sheet,
        private string $timezone,
    ) {}

    /**
     * @param  array<int|string, string|null>  $columns  headers, or header => number format
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $total  a last row, in bold
     */
    public function table(array $columns, iterable $rows, ?array $total = null): void
    {
        $columns = $this->formatsByHeader($columns);
        $headers = array_keys($columns);

        $this->line(array_fill_keys($headers, null), array_combine($headers, $headers), bold: true);

        foreach ($rows as $values) {
            $this->line($columns, $values);
        }

        if ($total !== null) {
            $this->line($columns, $total, bold: true);
        }

        $this->autoSize(count($columns));
    }

    /**
     * A title in bold, then a label and its value per line, then an empty line.
     *
     * @param  array<string, mixed>  $values  label => value
     * @param  array<string, string>  $formats  label => number format, where one is needed
     */
    public function facts(string $title, array $values, array $formats = [], ?string $note = null): void
    {
        $this->put(1, $title);
        $this->sheet->getStyle('A' . $this->row)->getFont()->setBold(true);
        $this->put(3, $note);
        $this->row++;

        foreach ($values as $label => $value) {
            $this->put(1, $label);
            $this->put(2, $value, $formats[$label] ?? null);
            $this->row++;
        }

        $this->row++;
        $this->autoSize(2);
    }

    /**
     * @param  array<string, string|null>  $columns
     * @param  array<string, mixed>  $values
     */
    private function line(array $columns, array $values, bool $bold = false): void
    {
        $column = 1;
        foreach ($columns as $header => $format) {
            $this->put($column++, $values[$header] ?? null, $format);
        }

        if ($bold) {
            $last = Coordinate::stringFromColumnIndex(count($columns));
            $this->sheet->getStyle('A' . $this->row . ':' . $last . $this->row)->getFont()->setBold(true);
        }

        $this->row++;
    }

    private function put(int $column, mixed $value, ?string $format = null): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $coordinate = Coordinate::stringFromColumnIndex($column) . $this->row;

        if ($value instanceof CarbonInterface) {
            $format ??= self::DATETIME;
            $serial = $format === self::DATE ? $this->dateSerial($value) : $this->serial($value);
            $this->sheet->setCellValue($coordinate, $serial);
        } elseif (is_int($value) || is_float($value)) {
            $this->sheet->setCellValue($coordinate, $value);
        } else {
            $text = is_bool($value) ? ($value ? 'Ja' : 'Nee') : (string) $value;
            $this->sheet->setCellValueExplicit($coordinate, $text, DataType::TYPE_STRING);
        }

        if ($format !== null) {
            $this->sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
        }
    }

    /**
     * @param  array<int|string, string|null>  $columns
     * @return array<string, string|null>
     */
    private function formatsByHeader(array $columns): array
    {
        $formats = [];
        foreach ($columns as $key => $value) {
            [$header, $format] = is_int($key) ? [$value, null] : [$key, $value];
            $formats[$header] = $format;
        }

        return $formats;
    }

    private function autoSize(int $column_count): void
    {
        for ($column = 1; $column <= $column_count; $column++) {
            $this->sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }

    /**
     * A calendar date (a milestone, an order date) is the same day everywhere;
     * shifting it into the timezone could move it to the day before.
     */
    private function dateSerial(CarbonInterface $value): float
    {
        return ExcelDate::formattedPHPToExcel($value->year, $value->month, $value->day);
    }

    private function serial(CarbonInterface $value): float
    {
        $local = $value->copy()->setTimezone($this->timezone);

        return ExcelDate::formattedPHPToExcel(
            $local->year,
            $local->month,
            $local->day,
            $local->hour,
            $local->minute,
            $local->second,
        );
    }
}
