<?php

namespace App\Domain\Imports;

use App\Exceptions\Refusal;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * One tab of an uploaded spreadsheet, read as a header row and the rows below it.
 *
 * Columns are known by their header text, because that is what a saved mapping
 * remembers: a supplier adding a column next month shifts every position, not
 * the names. A column without a header is called after its letter, and a header
 * that occurs twice gets a number, so every column still has a name to pick.
 */
class ImportSheet
{
    /** @var array<int, array<int, mixed>> */
    private array $grid;

    /**
     * @param  array<int, string>  $sheet_names
     */
    private function __construct(Worksheet $worksheet, private array $sheet_names, private string $sheet_name)
    {
        $this->grid = $worksheet->toArray(null, true, false, false);
    }

    public static function open(string $path, ?string $sheet_name = null): self
    {
        try {
            /** @var BaseReader $reader */
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            }

            $sheet_names = $reader->listWorksheetNames($path);
            $chosen = in_array($sheet_name, $sheet_names, true) ? $sheet_name : ($sheet_names[0] ?? null);

            if ($chosen !== null && !$reader instanceof Csv) {
                $reader->setLoadSheetsOnly([$chosen]);
            }

            $worksheet = $reader->load($path)->getSheet(0);
        } catch (Throwable) {
            throw new Refusal(
                'Dit bestand kan niet gelezen worden. Gebruik een Excel-bestand (.xlsx of .xls), een CSV- of een ODS-bestand.'
            );
        }

        return new self($worksheet, $sheet_names, $chosen ?? $worksheet->getTitle());
    }

    /** @return array<int, string> */
    public function sheetNames(): array
    {
        return $this->sheet_names;
    }

    public function sheetName(): string
    {
        return $this->sheet_name;
    }

    /**
     * The columns under the header row, each with a few values from the rows below
     * so whoever maps them can see what is in there.
     *
     * @return array<int, array{name: string, samples: array<int, string>}>
     */
    public function columns(int $header_row): array
    {
        $names = $this->headerNames($header_row);
        $samples = array_fill_keys(array_keys($names), []);

        $missing = count($names) * 3;

        foreach (array_slice($this->grid, $header_row) as $row) {
            foreach (array_keys($names) as $index) {
                $value = self::text($row[$index] ?? null);

                if ($value !== null && count($samples[$index]) < 3) {
                    $samples[$index][] = mb_strimwidth($value, 0, 60, '…');
                    $missing--;
                }
            }

            if ($missing === 0) {
                break;
            }
        }

        return array_values(array_map(
            fn (int $index) => ['name' => $names[$index], 'samples' => $samples[$index]],
            array_keys($names),
        ));
    }

    /**
     * Every row below the header, keyed by the line number a person sees in Excel,
     * as header name => cell value. Rows with nothing in them are left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(int $header_row): array
    {
        $names = $this->headerNames($header_row);
        $rows = [];

        foreach (array_slice($this->grid, $header_row, null, true) as $offset => $row) {
            $values = [];

            foreach ($names as $index => $name) {
                $values[$name] = $row[$index] ?? null;
            }

            if (array_filter($values, fn ($value) => self::text($value) !== null) === []) {
                continue;
            }

            $rows[$offset + 1] = $values;
        }

        return $rows;
    }

    /**
     * A cell as text: trimmed, empty as null, and a whole number without the ".0"
     * a numeric cell would otherwise carry into an article number.
     */
    public static function text(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = number_format($value, 0, '', '');
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    /** @return array<int, string> column index => name */
    private function headerNames(int $header_row): array
    {
        $header = $this->grid[$header_row - 1] ?? [];
        $width = count($this->grid[0] ?? []);
        $names = [];
        $seen = [];

        for ($index = 0; $index < $width; $index++) {
            $name = self::text($header[$index] ?? null) ?? 'Kolom ' . Coordinate::stringFromColumnIndex($index + 1);
            $key = mb_strtolower($name);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $names[$index] = $seen[$key] > 1 ? $name . ' (' . $seen[$key] . ')' : $name;
        }

        return $this->withoutEmptyTrailingColumns($names, $header_row);
    }

    /**
     * toArray() reaches as far as the widest row anywhere, which in a sheet with a
     * stray note in column Z gives twenty columns called "Kolom …" with nothing
     * under them. Those are dropped; an empty column between filled ones is kept.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function withoutEmptyTrailingColumns(array $names, int $header_row): array
    {
        $header = $this->grid[$header_row - 1] ?? [];

        for ($index = count($names) - 1; $index >= 0; $index--) {
            if (self::text($header[$index] ?? null) !== null) {
                break;
            }

            $filled = false;

            foreach (array_slice($this->grid, $header_row) as $row) {
                if (self::text($row[$index] ?? null) !== null) {
                    $filled = true;
                    break;
                }
            }

            if ($filled) {
                break;
            }

            unset($names[$index]);
        }

        return $names;
    }
}
