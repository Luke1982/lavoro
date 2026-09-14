<?php

namespace Tests\Feature\Images;

use App\Models\Event;
use App\Models\Image;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Renaming and annotating each have a permission of their own, and those are what
 * the buttons ask for. The route used to validate both as an upload instead.
 *
 * Feedback on an event goes by the event, not by the image permissions: only for
 * photos that hang from that event, or naming any event would open every photo.
 */
class PhotoPermissionsTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private Product $product;

    private Image $image;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->product = Product::factory()->create();
        $this->image = $this->photoOn($this->product);
    }

    public function test_renaming_takes_the_rename_permission(): void
    {
        $this->actingAs($this->userWithPermissions('image.update'))
            ->postJson(route('images.update', $this->image), $this->on($this->product, ['newTitle' => 'Typeplaatje']))
            ->assertOk();

        $this->assertSame('Typeplaatje', $this->image->fresh()->name);
    }

    public function test_the_upload_permission_does_not_rename(): void
    {
        $this->actingAs($this->userWithPermissions('image.upload'))
            ->postJson(route('images.update', $this->image), $this->on($this->product, ['newTitle' => 'Typeplaatje']))
            ->assertForbidden();
    }

    /** The annotation form sends its empty title along, which must not count as renaming. */
    public function test_annotating_takes_only_the_annotate_permission(): void
    {
        $this->actingAs($this->userWithPermissions('image.edit'))
            ->postJson(route('images.update', $this->image), $this->on($this->product, [
                'imageToUpdate' => UploadedFile::fake()->image('image.jpg'),
                'newTitle' => '',
            ]))
            ->assertOk();

        $this->assertNotSame($this->image->path, $this->image->fresh()->path);
    }

    public function test_the_rename_permission_does_not_annotate(): void
    {
        $this->actingAs($this->userWithPermissions('image.update'))
            ->postJson(route('images.update', $this->image), $this->on($this->product, [
                'imageToUpdate' => UploadedFile::fake()->image('image.jpg'),
            ]))
            ->assertForbidden();
    }

    public function test_an_update_that_changes_nothing_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('images.update', $this->image), $this->on($this->product))
            ->assertJsonValidationErrors(['imageToUpdate', 'newTitle']);
    }

    public function test_feedback_uploads_renames_promotes_and_removes_photos_of_its_event(): void
    {
        $event = Event::factory()->create();
        $this->actingAs($this->userWithPermissions('event.provide_feedback'));

        $this->postJson('/api/images', $this->on($event, ['images' => [UploadedFile::fake()->image('image.jpg')]]))
            ->assertCreated();

        $image = $event->images()->sole();

        $this->postJson('/api/images/update/' . $image->id, $this->on($event, ['newTitle' => 'Na de reparatie']))
            ->assertOk();
        $this->postJson('/api/images/' . $image->id . '/set-main', $this->on($event))
            ->assertOk();
        $this->deleteJson('/api/images/' . $image->id, $this->on($event))
            ->assertOk();

        $this->assertModelMissing($image);
    }

    public function test_feedback_on_an_event_opens_no_photo_of_another_record(): void
    {
        $event = Event::factory()->create();
        $this->actingAs($this->userWithPermissions('event.provide_feedback'));

        $this->postJson('/api/images/update/' . $this->image->id, $this->on($event, ['newTitle' => 'Gekaapt']))
            ->assertForbidden();
        $this->postJson('/api/images/' . $this->image->id . '/set-main', $this->on($event))
            ->assertForbidden();
        $this->deleteJson('/api/images/' . $this->image->id, $this->on($event))
            ->assertForbidden();

        $this->assertSame('Foto', $this->image->fresh()->name);
        Storage::disk('public')->assertExists($this->image->path);
    }

    private function on(Model $record, array $data = []): array
    {
        return [...$data, 'imageable_type' => $record::class, 'imageable_id' => $record->getKey()];
    }
}
