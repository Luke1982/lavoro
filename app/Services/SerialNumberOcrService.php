<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\TesseractOCR;

class SerialNumberOcrService
{
    private const SERIAL_PREFIXES = [
        'serienummer', 'serienr', 'serial no', 'serial number', 'serial', 'ser no', 's/n', 'sn', 'no', 'nr',
    ];

    /**
     * Run OCR on an image and return likely serial-number candidates plus the raw text.
     *
     * @return array{candidates: array<int, string>, raw: string}
     */
    public function extractCandidates(string $source_path): array
    {
        $prepared_path = $this->preprocess($source_path);

        try {
            $raw = (new TesseractOCR($prepared_path ?? $source_path))
                ->psm(6)
                ->run();
        } finally {
            if ($prepared_path !== null && is_file($prepared_path)) {
                @unlink($prepared_path);
            }
        }

        return [
            'candidates' => $this->parseCandidates($raw),
            'raw' => trim($raw),
        ];
    }

    /**
     * Grayscale, upscale and sharpen the image so tesseract has an easier time.
     * Returns the path to a temporary file, or null when preprocessing is unavailable.
     */
    private function preprocess(string $source_path): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $image = new \Imagick($source_path);
            $image->autoOrient();
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);

            $width = $image->getImageWidth();
            if ($width > 0 && $width < 1500) {
                $factor = 1500 / $width;
                $image->resizeImage(
                    (int) ($width * $factor),
                    (int) ($image->getImageHeight() * $factor),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            $image->normalizeImage();
            $image->sharpenImage(0, 1);
            $image->setImageFormat('png');

            $target_path = tempnam(sys_get_temp_dir(), 'ocr_');
            $image->writeImage($target_path);
            $image->clear();

            return $target_path;
        } catch (\Throwable $e) {
            Log::warning('Serial OCR preprocessing failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Turn raw OCR text into a ranked, de-duplicated list of serial-number candidates.
     *
     * @return array<int, string>
     */
    private function parseCandidates(string $raw): array
    {
        $candidates = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $after_prefix = $this->stripSerialPrefix($line);
            if ($after_prefix !== null && $this->looksLikeSerial($after_prefix)) {
                $candidates[] = $after_prefix;
            }

            foreach (preg_split('/\s+/', $line) as $token) {
                $token = trim($token, " \t\n\r\0\x0B:.,;()[]{}\"'");
                if ($this->looksLikeSerial($token)) {
                    $candidates[] = $token;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));

        usort($candidates, fn ($a, $b) => $this->score($b) <=> $this->score($a));

        return array_slice($candidates, 0, 12);
    }

    private function stripSerialPrefix(string $line): ?string
    {
        $lower = mb_strtolower($line);

        foreach (self::SERIAL_PREFIXES as $prefix) {
            $needle = $prefix . ':';
            $pos = mb_strpos($lower, $needle);
            if ($pos !== false) {
                return trim(mb_substr($line, $pos + mb_strlen($needle)));
            }
        }

        return null;
    }

    private function looksLikeSerial(string $value): bool
    {
        $length = mb_strlen($value);
        if ($length < 3 || $length > 40) {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9\-\/]+$/', $value)) {
            return false;
        }

        return preg_match('/\d/', $value) === 1;
    }

    private function score(string $value): int
    {
        $score = 0;

        if (preg_match('/[A-Za-z]/', $value) && preg_match('/\d/', $value)) {
            $score += 3;
        }

        if (preg_match('/[\-\/]/', $value)) {
            $score += 1;
        }

        $length = mb_strlen($value);
        if ($length >= 6 && $length <= 24) {
            $score += 2;
        }

        return $score;
    }
}
