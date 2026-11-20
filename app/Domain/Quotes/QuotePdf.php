<?php

namespace App\Domain\Quotes;

use App\Models\Company;
use App\Models\Image;
use App\Models\Quote;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A quote on paper, made from what the customer receives (QuoteForCustomer):
 * the one attached to the mail, the one downloaded from the customer's page,
 * and the signed copy kept once they said yes. The logo is the company's
 * current one; everything else comes from the snapshot. Only the letters used
 * are embedded, so it stays light enough to mail.
 */
final class QuotePdf
{
    /**
     * @param  array<string, mixed>  $document  a completed snapshot
     * @param  array<string, mixed>|null  $signed  the yes, as signed() gives it, for the signed copy
     */
    public static function render(array $document, ?array $signed = null): string
    {
        $pdf = Pdf::loadView('pdf.quote', [
            'document' => $document,
            'logo' => Company::pdfLogo(),
            'images' => $document['layout']['images'] ? self::images($document) : [],
            'signed' => $signed,
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set(['defaultFont' => 'Helvetica', 'isFontSubsettingEnabled' => true]);

        return $pdf->output();
    }

    /** General terms written as a text, on paper under the company's logo, to hand over with a quote. */
    public static function renderTerms(string $html): string
    {
        $pdf = Pdf::loadView('pdf.quote-terms', [
            'html' => $html,
            'logo' => Company::pdfLogo(),
            'seller' => Company::main()?->details() ?? [],
        ])->setPaper('a4');
        $pdf->getDomPDF()->getOptions()->set(['defaultFont' => 'Helvetica', 'isFontSubsettingEnabled' => true]);

        return $pdf->output();
    }

    /**
     * The photos of the lines at the top, small, by image id. A photo removed
     * from the catalogue since is simply not drawn.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    private static function images(array $document): array
    {
        $ids = array_filter(array_column($document['lines'], 'image_id'));

        return Image::whereIn('id', $ids)->get(['id', 'path'])
            ->mapWithKeys(fn (Image $image) => [$image->id => PdfImage::dataUri($image->path)])
            ->filter()
            ->all();
    }

    /** @param array<string, mixed> $document */
    public static function filename(array $document, bool $signed = false): string
    {
        return 'Offerte ' . $document['quote']['number'] . ' versie ' . $document['quote']['version']
            . ($signed ? ' getekend' : '') . '.pdf';
    }

    /**
     * The yes as the signed copy prints it.
     *
     * @return array<string, mixed>|null null while the quote is not accepted
     */
    public static function signed(Quote $quote): ?array
    {
        if ($quote->accepted_name === null) {
            return null;
        }

        return [
            'name' => $quote->accepted_name,
            'signature' => $quote->accepted_signature,
            'date' => $quote->decided_at?->format('d-m-Y'),
            'via' => $quote->accepted_via?->phrase(),
            'terms_name' => $quote->accepted_terms_name,
            'starts_within_cooling_off' => (bool) $quote->starts_within_cooling_off,
            'cooling_off_until' => $quote->coolingOffUntil()?->format('d-m-Y'),
        ];
    }
}
