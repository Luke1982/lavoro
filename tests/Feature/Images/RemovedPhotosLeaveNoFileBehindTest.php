<?php

namespace Tests\Feature\Images;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Photos live on the public disk, but removing or annotating one deleted from the
 * default disk, so the file stayed behind for good.
 */
class RemovedPhotosLeaveNoFileBehindTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->product = Product::factory()->create();
    }

    public function test_removing_a_photo_removes_its_file(): void
    {
        $image = $this->photoOn($this->product);

        $this->remove($image)->assertOk();

        $this->assertModelMissing($image);
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_annotating_a_photo_replaces_its_file_in_the_same_folder(): void
    {
        $image = $this->photoOn($this->product);

        $this->annotate($image)->assertOk();

        $annotated_path = $image->fresh()->path;
        $this->assertSame(dirname($image->path), dirname($annotated_path));
        Storage::disk('public')->assertMissing($image->path);
        Storage::disk('public')->assertExists($annotated_path);
    }

    public function test_a_file_two_older_photos_share_stays_until_the_last_of_them_goes(): void
    {
        $first = $this->photoOn($this->product, 'uploaded/product/' . $this->product->id . '/image.jpg');
        $second = $this->photoOn($this->product, $first->path);

        $this->remove($first)->assertOk();
        Storage::disk('public')->assertExists($second->path);

        $this->remove($second)->assertOk();
        Storage::disk('public')->assertMissing($second->path);
    }

    public function test_annotating_one_of_two_photos_that_share_a_file_leaves_the_other_its_file(): void
    {
        $annotated = $this->photoOn($this->product, 'uploaded/product/' . $this->product->id . '/image.jpg');
        $untouched = $this->photoOn($this->product, $annotated->path);

        $this->annotate($annotated)->assertOk();

        Storage::disk('public')->assertExists($untouched->path);
    }

    private function remove(Image $image): TestResponse
    {
        return $this->actingAs($this->admin())->deleteJson(route('images.destroy', $image), [
            'imageable_type' => Product::class,
            'imageable_id' => $this->product->id,
        ]);
    }

    private function annotate(Image $image): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('images.update', $image), [
            'imageToUpdate' => UploadedFile::fake()->image(basename($image->path)),
            'newTitle' => '',
            'imageable_type' => Product::class,
            'imageable_id' => $this->product->id,
        ]);
    }
}
