<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SerialOcrTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temp_files = [];

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/ocr/serial')->assertUnauthorized();
    }

    public function test_image_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/ocr/serial', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_extracts_serial_number_candidates_from_a_photo(): void
    {
        $this->skip_without_ocr_stack();

        $image = $this->rating_plate_image('A1B2-3390-77XK');

        $response = $this->actingAs(User::factory()->create())
            ->post('/api/ocr/serial', ['image' => $image], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonStructure(['candidates', 'raw']);

        $this->assertContains('A1B2-3390-77XK', $response->json('candidates'));
    }

    private function skip_without_ocr_stack(): void
    {
        if (!class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick extension is not available.');
        }

        if (trim((string) shell_exec('command -v tesseract')) === '') {
            $this->markTestSkipped('The tesseract binary is not installed.');
        }
    }

    private function rating_plate_image(string $serial_number): UploadedFile
    {
        $image = new \Imagick;
        $image->newImage(640, 360, new \ImagickPixel('#d8d8d8'));
        $image->setImageFormat('png');

        $draw = new \ImagickDraw;
        $draw->setFillColor('black');
        $draw->setFont('DejaVu-Sans-Bold');

        $lines = [
            [26, 60, 'ACME HEAT PUMP CO.'],
            [20, 110, 'Model: WP-450X'],
            [24, 240, 'S/N: ' . $serial_number],
        ];

        foreach ($lines as [$size, $y, $text]) {
            $draw->setFontSize($size);
            $image->annotateImage($draw, 30, $y, 0, $text);
        }

        $path = tempnam(sys_get_temp_dir(), 'ocr_test_');
        $this->temp_files[] = $path;
        $image->writeImage($path);
        $image->clear();

        return new UploadedFile($path, 'plate.png', 'image/png', null, true);
    }
}
