<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Maakt wat er binnenkomt terug tot iets dat goed te bekijken is zonder een schijf
 * vol te zetten.
 *
 * Twee regels staan overal boven. Verkleinen vergroot nooit: een clip van 480
 * hoog blijft 480 hoog. En mislukken doet geen kwaad: kan Imagick het niet lezen,
 * of staat ffmpeg er niet, dan blijft het origineel gewoon staan en gaat er een
 * regel naar het log. Een storingsmelding met een groot bestand is meer waard dan
 * een bespaarde megabyte.
 */
class MediaDownscaler
{
    public function canProcessImages(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function canProcessVideo(): bool
    {
        return $this->binaryWorks((string) config('customerupload.ffmpeg', 'ffmpeg'));
    }

    /**
     * Verkleint een foto op zijn plaats en geeft terug waar hij daarna staat. Bij
     * HEIC verandert dat pad, want dat formaat wordt jpeg — de helft van de
     * mailprogramma's en browsers laat HEIC anders niet zien.
     */
    public function image(string $absolute_path): string
    {
        if (!$this->canProcessImages() || !is_file($absolute_path)) {
            return $absolute_path;
        }

        $max_edge = (int) config('customerupload.image.max_edge', 1920);
        $quality = (int) config('customerupload.image.quality', 82);

        try {
            $image = new \Imagick($absolute_path);
        } catch (\Throwable $e) {
            Log::warning('Kon afbeelding niet openen om te verkleinen', [
                'path' => $absolute_path,
                'error' => $e->getMessage(),
            ]);

            return $absolute_path;
        }

        try {
            /** Een bewegende gif overleeft een enkelvoudige write niet: die zou één plaatje worden. */
            if ($image->getNumberImages() > 1) {
                return $absolute_path;
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $needs_resize = max($width, $height) > $max_edge;
            $target = $this->targetPathFor($absolute_path);
            $needs_convert = $target !== $absolute_path;

            /** Niets te winnen: opnieuw opslaan zou de foto alleen maar slechter maken. */
            if (!$needs_resize && !$needs_convert) {
                return $absolute_path;
            }

            $image->autoOrient();
            $image->stripImage();

            if ($needs_resize) {
                $scale = $max_edge / max($width, $height);
                $image->resizeImage(
                    (int) round($width * $scale),
                    (int) round($height * $scale),
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            if ($needs_convert) {
                $image->setImageFormat('jpeg');
            }

            if (strtolower($image->getImageFormat()) === 'jpeg') {
                $image->setImageCompressionQuality($quality);
            }

            $image->writeImage($target);

            if ($needs_convert && is_file($absolute_path)) {
                @unlink($absolute_path);
            }

            return $target;
        } catch (\Throwable $e) {
            Log::warning('Kon afbeelding niet verkleinen', [
                'path' => $absolute_path,
                'error' => $e->getMessage(),
            ]);

            return $absolute_path;
        } finally {
            $image->clear();
        }
    }

    /**
     * Zet een video om naar iets dat overal speelt en geeft terug waar hij daarna
     * staat. Levert het niets op, dan blijft het origineel: een omzetting die groter
     * uitvalt is een omzetting die niet had gehoeven.
     */
    public function video(string $absolute_path): string
    {
        if (!is_file($absolute_path) || !$this->canProcessVideo()) {
            return $absolute_path;
        }

        $target = $this->withExtension($absolute_path, 'mp4');
        $temporary = $absolute_path . '.downscaled.mp4';

        try {
            $process = new Process([
                (string) config('customerupload.ffmpeg', 'ffmpeg'),
                '-y',
                '-i', $absolute_path,
                '-vf', "scale=-2:'min(" . (int) config('customerupload.video.max_height', 720) . ",ih)'",
                '-c:v', 'libx264',
                '-crf', (string) (int) config('customerupload.video.crf', 28),
                '-preset', (string) config('customerupload.video.preset', 'medium'),
                '-c:a', 'aac',
                '-b:a', (string) config('customerupload.video.audio_bitrate', '128k'),
                '-movflags', '+faststart',
                $temporary,
            ]);

            $process->setTimeout((float) config('customerupload.ffmpeg_timeout', 900));
            $process->mustRun();

            clearstatcache(true, $temporary);
            clearstatcache(true, $absolute_path);

            if (!is_file($temporary) || filesize($temporary) >= filesize($absolute_path)) {
                @unlink($temporary);

                return $absolute_path;
            }

            @unlink($absolute_path);
            rename($temporary, $target);

            return $target;
        } catch (ProcessFailedException|\Throwable $e) {
            @unlink($temporary);

            Log::warning('Kon video niet verkleinen', [
                'path' => $absolute_path,
                'error' => $e->getMessage(),
            ]);

            return $absolute_path;
        }
    }

    /** Alleen HEIC en HEIF verhuizen naar een ander formaat; de rest houdt het zijne. */
    private function targetPathFor(string $absolute_path): string
    {
        $extension = strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION));

        return in_array($extension, ['heic', 'heif'], true)
            ? $this->withExtension($absolute_path, 'jpg')
            : $absolute_path;
    }

    private function withExtension(string $absolute_path, string $extension): string
    {
        $directory = dirname($absolute_path);
        $name = pathinfo($absolute_path, PATHINFO_FILENAME);

        return $directory . DIRECTORY_SEPARATOR . $name . '.' . $extension;
    }

    /**
     * Eén keer per aanroep uitgeprobeerd in plaats van geraden aan het pad: een
     * binary die er staat maar niet mag draaien is even onbruikbaar als een die er
     * niet is.
     */
    private function binaryWorks(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
