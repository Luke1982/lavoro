<?php

namespace Tests\Feature;

use App\Services\MediaDownscaler;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Wat een klant opstuurt komt van een telefoon en is daarmee vier keer groter dan
 * het ooit bekeken wordt. Verkleinen mag alleen nooit ten koste gaan van wat er te
 * zien is — en als het niet kan, blijft het origineel staan.
 */
class MediaDownscalerTest extends TestCase
{
    private string $work_dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->work_dir = storage_path('framework/testing/downscaler-' . uniqid());
        mkdir($this->work_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->work_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->work_dir);

        parent::tearDown();
    }

    private function jpeg(int $width, int $height): string
    {
        $path = $this->work_dir . '/' . $width . 'x' . $height . '.jpg';

        $image = new \Imagick;
        $image->newImage($width, $height, new \ImagickPixel('#3366aa'));
        $image->setImageFormat('jpeg');
        $image->writeImage($path);
        $image->clear();

        return $path;
    }

    private function requireImagick(): void
    {
        if (!class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is hier niet geïnstalleerd.');
        }
    }

    public function test_a_large_photo_is_brought_back_to_the_configured_long_edge(): void
    {
        $this->requireImagick();
        config(['customerupload.image.max_edge' => 1920]);

        $path = $this->jpeg(4000, 3000);
        $before = filesize($path);

        $result = (new MediaDownscaler)->image($path);

        $image = new \Imagick($result);
        $this->assertSame(1920, $image->getImageWidth());
        $this->assertSame(1440, $image->getImageHeight());

        clearstatcache(true, $result);
        $this->assertLessThan($before, filesize($result));
    }

    public function test_a_portrait_photo_is_measured_on_its_longest_side(): void
    {
        $this->requireImagick();
        config(['customerupload.image.max_edge' => 1920]);

        $result = (new MediaDownscaler)->image($this->jpeg(3000, 4000));

        $image = new \Imagick($result);
        $this->assertSame(1920, $image->getImageHeight());
        $this->assertSame(1440, $image->getImageWidth());
    }

    public function test_a_photo_that_is_already_small_enough_is_left_untouched(): void
    {
        $this->requireImagick();
        config(['customerupload.image.max_edge' => 1920]);

        $path = $this->jpeg(800, 600);
        $before = file_get_contents($path);

        $result = (new MediaDownscaler)->image($path);

        $this->assertSame($path, $result);
        $this->assertSame($before, file_get_contents($result));
    }

    public function test_an_unreadable_file_keeps_its_original_and_says_so(): void
    {
        $path = $this->work_dir . '/broken.jpg';
        file_put_contents($path, 'dit is geen afbeelding');

        $result = (new MediaDownscaler)->image($path);

        $this->assertSame($path, $result);
        $this->assertFileExists($path);
    }

    /**
     * Met een echte ffmpeg erbij, want de filterregel is het enige stuk van deze
     * klasse dat door een ander programma gelezen wordt. Dat de aanhalingstekens
     * rond min(720,ih) meekomen zonder shell eromheen valt niet te beredeneren —
     * alleen te proberen.
     */
    private function clipOfSize(string $size, string $extension = 'mp4'): string
    {
        $path = $this->work_dir . '/' . $size . '.' . $extension;

        $process = new Process([
            (string) config('customerupload.ffmpeg', 'ffmpeg'),
            '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=' . $size . ':rate=25:duration=1',
            '-c:v', 'libx264', '-crf', '18',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $path;
    }

    private function heightOf(string $path): int
    {
        $process = new Process([
            (string) config('customerupload.ffprobe', 'ffprobe'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=height',
            '-of', 'csv=p=0',
            $path,
        ]);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function requireFfmpeg(): void
    {
        if (!(new MediaDownscaler)->canProcessVideo()) {
            $this->markTestSkipped('ffmpeg is hier niet geïnstalleerd.');
        }
    }

    public function test_a_large_clip_comes_back_at_the_configured_height(): void
    {
        $this->requireFfmpeg();
        config(['customerupload.video.max_height' => 720]);

        $path = $this->clipOfSize('1920x1080');
        $before = filesize($path);

        $result = (new MediaDownscaler)->video($path);

        clearstatcache(true, $result);
        $this->assertSame($path, $result);
        $this->assertSame(720, $this->heightOf($result));
        $this->assertLessThan($before, filesize($result));
    }

    /**
     * Een telefoon stuurt .mov. Dat wordt .mp4, en het origineel hoort dan weg te
     * zijn in plaats van naast de omgezette versie te blijven liggen.
     */
    public function test_a_clip_that_is_not_mp4_is_converted_and_the_original_removed(): void
    {
        $this->requireFfmpeg();

        $path = $this->clipOfSize('1280x720', 'mov');

        $result = (new MediaDownscaler)->video($path);

        $this->assertSame(substr($path, 0, -4) . '.mp4', $result);
        $this->assertFileExists($result);
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_small_clip_is_never_blown_up(): void
    {
        $this->requireFfmpeg();
        config(['customerupload.video.max_height' => 720]);

        $result = (new MediaDownscaler)->video($this->clipOfSize('320x240'));

        $this->assertSame(240, $this->heightOf($result));
    }

    public function test_video_is_left_alone_when_ffmpeg_is_not_there(): void
    {
        config(['customerupload.ffmpeg' => '/nergens/ffmpeg']);

        $path = $this->work_dir . '/clip.mp4';
        file_put_contents($path, 'niet echt een video');

        $downscaler = new MediaDownscaler;

        $this->assertFalse($downscaler->canProcessVideo());
        $this->assertSame($path, $downscaler->video($path));
        $this->assertFileExists($path);
    }
}
