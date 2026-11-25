<?php

namespace App\Domain\Assistant\Evals;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Prints a made-up signed offerte as a PDF, so the offerte scenarios are not
 * limited to the one real file there is. Laid out the way installers' quotes
 * tend to be: a letterhead, the customer, lines with quantity and price, and the
 * conditions that matter in small print underneath.
 *
 * Each file is named after a hash of what is in it, so a changed fixture is
 * printed again instead of an old copy being read under the same name.
 */
final class QuoteFixture
{
    public static function directory(): string
    {
        return EvalFiles::root() . '/fixtures';
    }

    /**
     * @param  array{
     *     supplier: string,
     *     number: string,
     *     date: string,
     *     customer: array<int, string>,
     *     lines: array<int, array{0: string, 1: float|int, 2: float|int}>,
     *     notes: array<int, string>,
     *     signed_by: string,
     * }  $quote
     */
    public static function pdf(array $quote, string $name): string
    {
        $html = self::html($quote);
        $path = self::generated($name, $html, 'pdf');

        if (!is_file($path)) {
            EvalFiles::write($path, Pdf::loadHTML($html)->output());
        }

        return $path;
    }

    /**
     * A small price table as a picture, the way somebody photographs or screenshots
     * a spreadsheet: first column a name, then amounts excl. and incl. btw.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     */
    public static function tableImage(array $rows, string $name): string
    {
        $cells = '';

        foreach ($rows as [$label, $excl, $incl]) {
            $cells .= '<tr><td>' . self::escaped($label) . '</td><td>' . self::escaped($excl) . '</td><td>' . self::escaped($incl) . '</td></tr>';
        }

        $html = '<html><body style="font-family: DejaVu Sans; font-size: 14px">'
            . '<table cellpadding="6"><tr><td></td><td>Excl BTW</td><td>Incl BTW</td></tr>' . $cells . '</table></body></html>';
        $path = self::generated($name, $html, 'png');

        if (is_file($path)) {
            return $path;
        }

        $pdftoppm = self::pdftoppm() ?? throw new RuntimeException('pdftoppm ontbreekt; ' . $path . ' kan niet gemaakt worden.');
        $prefix = dirname($path) . '/' . pathinfo($path, PATHINFO_FILENAME);
        $pdf = EvalFiles::write($prefix . '.pdf', Pdf::loadHTML($html)->setPaper([0, 0, 360, 200])->output());
        exec(escapeshellarg($pdftoppm) . ' -png -r 110 -singlefile ' . escapeshellarg($pdf) . ' ' . escapeshellarg($prefix) . ' 2>&1', $output, $code);

        if (!unlink($pdf)) {
            throw new RuntimeException('Kan ' . $pdf . ' niet weghalen.');
        }

        if ($code !== 0 || !is_file($path)) {
            throw new RuntimeException('pdftoppm maakte geen ' . $path . ' (code ' . $code . '): ' . implode(' ', $output));
        }

        return $path;
    }

    /** Whether pictures can be made here at all, found on the path without running anything. */
    public static function canDrawPictures(): bool
    {
        return self::pdftoppm() !== null;
    }

    private static function pdftoppm(): ?string
    {
        return (new ExecutableFinder)->find('pdftoppm');
    }

    private static function generated(string $name, string $content, string $extension): string
    {
        return EvalFiles::folder(self::directory() . '/generated') . '/' . $name . '-' . substr(sha1($content), 0, 12) . '.' . $extension;
    }

    private static function escaped(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }

    private static function html(array $quote): string
    {
        $money = fn ($amount) => '€ ' . number_format((float) $amount, 2, ',', '.');
        $total = 0;
        $rows = '';

        foreach ($quote['lines'] as [$description, $quantity, $price]) {
            $line = $quantity * $price;
            $total += $line;
            $rows .= '<tr><td>' . nl2br(self::escaped($description)) . '</td><td class="n">' . number_format($quantity, 2, ',', '.')
                . '</td><td class="n">' . $money($price) . '</td><td class="n">' . $money($line) . '</td></tr>';
        }

        $vat = round($total * 0.21, 2);

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
            table { width: 100%; border-collapse: collapse; } td, th { padding: 4px; vertical-align: top; }
            th { text-align: left; border-bottom: 1px solid #333; } .n { text-align: right; white-space: nowrap; }
            .head { margin-bottom: 24px; } .small { font-size: 9px; color: #333; }
        </style></head><body>
            <div class="head"><strong>' . self::escaped($quote['supplier']) . '</strong></div>
            <h2>Offerte</h2>
            <p>' . implode('<br>', array_map(self::escaped(...), $quote['customer'])) . '</p>
            <p>Offertenummer: ' . self::escaped($quote['number']) . '<br>Datum: ' . self::escaped($quote['date']) . '</p>
            <p>Geachte heer/mevrouw,<br>Hartelijk dank voor uw aanvraag. Hierbij bieden wij u het volgende aan.</p>
            <table><tr><th>Omschrijving</th><th class="n">Aantal</th><th class="n">Prijs</th><th class="n">Totaal</th></tr>' . $rows . '</table>
            <p class="n">Totaal excl. btw ' . $money($total) . '<br>Btw 21% ' . $money($vat) . '<br><strong>Offertebedrag ' . $money($total + $vat) . '</strong></p>
            <p class="small">' . implode('<br>', array_map(self::escaped(...), $quote['notes'])) . '</p>
            <p>Voor akkoord: ' . self::escaped($quote['signed_by']) . '<br>Handtekening: <em>' . self::escaped($quote['signed_by']) . '</em> (getekend)</p>
        </body></html>';
    }
}
