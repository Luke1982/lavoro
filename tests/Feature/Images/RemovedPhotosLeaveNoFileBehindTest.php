<?php

namespace Tests\Feature\Images;

use App\Models\Image;
use App\Models\Product;
use App\Services\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Photos live on the tenant's public disk, but removing or annotating one deleted
 * from the default disk, so the file stayed behind for good and kept counting
 * against the customer's storage limit.
 */
class RemovedPhotosLeaveNoFileBehindTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

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

    /** The nightly reconcile measures what is on the tenant's disks, so a file left behind is billed. */
    public function test_a_removed_photo_stops_counting_against_the_storage_limit(): void
    {
        Storage::fake('local');
        $image = $this->photoOn($this->product);

        $this->assertGreaterThan(0, (new StorageQuota)->reconcile());

        $this->remove($image)->assertOk();

        $this->assertSame(0, (new StorageQuota)->reconcile());
    }

    public function test_a_removal_that_rolls_back_keeps_its_file(): void
    {
        $image = $this->photoOn($this->product);

        rescue(fn () => DB::transaction(function () use ($image) {
            DB::table('imageables')->where('image_id', $image->id)->delete();
            $image->delete();

            throw new RuntimeException('Teruggedraaid');
        }), report: false);

        $this->assertModelExists($image);
        Storage::disk('public')->assertExists($image->path);
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
