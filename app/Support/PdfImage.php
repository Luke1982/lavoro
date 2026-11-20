<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A picture from the tenant's disk, made small enough to go into a PDF: a few
 * catalogue photos at full size would make a quote too heavy to mail. A photo
 * is shrunk to fit a square and stored as JPEG on white, which is what DomPDF
 * draws best. A drawing (SVG) is small already and goes in as a drawing, with
 * each gradient replaced by its first colour: DomPDF cannot fill with a
 * gradient and paints it black. Null for what cannot be read, such as a file
 * that is gone.
 */
final class PdfImage
{
    public static function dataUri(?string $path, int $max_pixels = 240): ?string
    {
        $disk = Storage::disk('public');

        if ($path === null || !$disk->exists($path)) {
            return null;
        }

        if (str_ends_with(strtolower($path), '.svg')) {
            return 'data:image/svg+xml;base64,' . base64_encode(self::withoutGradients($disk->get($path)));
        }

        $source = @imagecreatefromstring($disk->get($path));

        if ($source === false) {
            return null;
        }

        $scale = min(1, $max_pixels / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        imagejpeg($canvas, null, 80);

        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    private static function withoutGradients(string $svg): string
    {
        preg_match_all(
            '/<(?:linear|radial)Gradient[^>]*\bid="([^"]+)"[^>]*>.*?<stop[^>]*\bstop-color="([^"]+)"/s',
            $svg,
            $gradients,
            PREG_SET_ORDER,
        );

        foreach ($gradients as [, $id, $color]) {
            $svg = str_replace('url(#' . $id . ')', $color, $svg);
        }

        return $svg;
    }
}
