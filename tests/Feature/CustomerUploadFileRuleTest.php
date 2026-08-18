<?php

namespace Tests\Feature;

use App\Rules\CustomerUploadFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * De poort waar alles van buiten langs moet. Per soort een eigen grens, want een
 * video van 100 MB is normaal en een pdf van 100 MB is dat niet, en de extensie
 * alleen zegt niets: die typt de afzender zelf.
 */
class CustomerUploadFileRuleTest extends TestCase
{
    private function fails(UploadedFile $file): array
    {
        $validator = Validator::make(['file' => $file], ['file' => [new CustomerUploadFile]]);

        return $validator->errors()->get('file');
    }

    public function test_a_photo_within_the_limit_is_accepted(): void
    {
        config(['customerupload.image.max_kb' => 25600]);

        $this->assertSame([], $this->fails(UploadedFile::fake()->image('storing.jpg', 800, 600)));
    }

    public function test_a_photo_over_the_limit_is_refused_by_name_and_size(): void
    {
        config(['customerupload.image.max_kb' => 2048]);

        $errors = $this->fails(UploadedFile::fake()->create('groot.jpg', 4000, 'image/jpeg'));

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('groot.jpg', $errors[0]);
        $this->assertStringContainsString('2 MB', $errors[0]);
        $this->assertStringContainsString('foto', $errors[0]);
    }

    public function test_a_video_is_measured_against_the_video_limit(): void
    {
        config(['customerupload.video.max_kb' => 204800, 'customerupload.image.max_kb' => 100]);

        $this->assertSame([], $this->fails(UploadedFile::fake()->create('clip.mp4', 5000, 'video/mp4')));
    }

    public function test_an_unlisted_extension_is_refused(): void
    {
        $errors = $this->fails(UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'));

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('script.exe', $errors[0]);
    }

    public function test_a_file_whose_content_disagrees_with_its_name_is_refused(): void
    {
        $errors = $this->fails(UploadedFile::fake()->create('foto.jpg', 10, 'application/x-msdownload'));

        $this->assertNotSame([], $errors);
    }

    public function test_a_pdf_is_accepted_as_a_document(): void
    {
        $this->assertSame([], $this->fails(UploadedFile::fake()->create('handleiding.pdf', 20, 'application/pdf')));
    }

    public function test_the_kind_is_named_for_the_caller(): void
    {
        $this->assertSame('image', CustomerUploadFile::kindFor(UploadedFile::fake()->image('a.jpg')));
        $this->assertSame('video', CustomerUploadFile::kindFor(UploadedFile::fake()->create('a.mp4', 1, 'video/mp4')));
        $this->assertSame(
            'document',
            CustomerUploadFile::kindFor(UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')),
        );
        $this->assertNull(CustomerUploadFile::kindFor(UploadedFile::fake()->create('a.exe', 1)));
    }
}
