<?php

namespace App\Support\Spreadsheets;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as CalculationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A sheet as the spreadsheet on a page saves it (cells, styles, merges and
 * column widths; see ProjectFinancialNotesUpdateRequest), copied into Excel.
 * Every cell keeps its place, so a sum still points at the numbers it added up.
 */
final class SpreadsheetSnapshot
{
    private const PIXELS_PER_CHARACTER = 7;

    /**
     * @param  array{data?: array, style?: array, mergeCells?: array, columns?: array}|null  $snapshot
     */
    public static function write(Worksheet $sheet, ?array $snapshot): void
    {
        $calculation = Calculation::getInstance($sheet->getParent());

        foreach ($snapshot['data'] ?? [] as $row => $cells) {
            foreach ($cells as $column => $value) {
                self::cell($sheet, Coordinate::stringFromColumnIndex($column + 1) . ($row + 1), $value, $calculation);
            }
        }

        foreach ($snapshot['mergeCells'] ?? [] as $origin => [$width, $height]) {
            if ($width > 1 || $height > 1) {
                [$column, $row] = Coordinate::coordinateFromString($origin);
                $last_column = Coordinate::columnIndexFromString($column) + $width - 1;
                $sheet->mergeCells($origin . ':' . Coordinate::stringFromColumnIndex($last_column) . ($row + $height - 1));
            }
        }

        foreach ($snapshot['style'] ?? [] as $coordinate => $css) {
            if (preg_match('/font-weight:\s*(bold|[6-9]00)/i', (string) $css)) {
                $sheet->getStyle($coordinate)->getFont()->setBold(true);
            }
        }

        foreach ($snapshot['columns'] ?? [] as $index => $column) {
            if (isset($column['width'])) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))
                    ->setWidth($column['width'] / self::PIXELS_PER_CHARACTER);
            }
        }
    }

    /**
     * A formula Excel cannot read stays text rather than breaking the file. A
     * number with a leading zero, like a phone number, stays text as well.
     */
    private static function cell(Worksheet $sheet, string $coordinate, mixed $value, Calculation $calculation): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        if (str_starts_with($value, '=') && self::isReadable($value, $calculation)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_FORMULA);
        } elseif (is_numeric($value) && !preg_match('/^-?0\d/', $value)) {
            $sheet->setCellValue($coordinate, $value + 0);
        } else {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
    }

    private static function isReadable(string $formula, Calculation $calculation): bool
    {
        try {
            $calculation->parseFormula($formula);

            return true;
        } catch (CalculationException) {
            return false;
        }
    }
}
