<?php

namespace Tests\Feature\Images;

use App\Jobs\DeleteOrphanedImagesJob;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Image;
use App\Models\Product;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A deleted record left its photos and their files behind for good, and so did
 * everything the database deleted along with it: the checks of a werkbon, the
 * parts, tickets and checks of a machine.
 */
class DeletedRecordsTakeTheirPhotosTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_deleted_order_takes_its_photos_and_those_of_its_checks_along(): void
    {
        $order = $this->order();
        $photos = [
            $this->photoOn($order),
            $this->photoOn($order, pivot: ['internal' => true]),
            $this->photoOn($this->checkOn($this->jobOn($order))),
        ];

        $this->actingAs($this->admin())
            ->delete(route('serviceorders.destroy', $order))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($order);
        $this->assertPhotosGone($photos);
    }

    public function test_a_deleted_machine_takes_the_photos_of_its_parts_tickets_and_checks_along(): void
    {
        $machine = $this->machine();
        $part = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $machine->id,
            'product_id' => $machine->product_id,
        ]);
        $photos = [
            $this->photoOn($machine),
            $this->photoOn($part),
            $this->photoOn(Ticket::factory()->create(['asset_id' => $machine->id])),
            $this->photoOn($this->checkOn($this->jobOn($this->order(), $machine))),
        ];

        $this->actingAs($this->admin())
            ->delete(route('assets.destroy', $machine))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($machine);
        $this->assertPhotosGone($photos);
    }

    public function test_a_deleted_keuring_takes_the_photos_of_its_checks_and_its_follow_ups_along(): void
    {
        $job = $this->jobOn($this->order());
        $follow_up = $this->jobOn($job->serviceOrder, $job->asset, ['parent_service_job_id' => $job->id]);
        $photos = [
            $this->photoOn($this->checkOn($job)),
            $this->photoOn($this->checkOn($follow_up)),
        ];

        $this->actingAs($this->admin())
            ->delete(route('servicejobs.destroy', $job))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($job);
        $this->assertPhotosGone($photos);
    }

    /** Queued any earlier, the job could still find the record it is meant to clean up after. */
    public function test_the_clean_up_is_queued_after_the_commit_and_never_for_a_soft_delete(): void
    {
        Queue::fake();
        $order = $this->order();

        DB::transaction(function () use ($order) {
            $order->delete();

            Queue::assertNotPushed(DeleteOrphanedImagesJob::class);
        });

        Queue::assertPushed(DeleteOrphanedImagesJob::class, 1);

        Event::factory()->create()->delete();

        Queue::assertPushed(DeleteOrphanedImagesJob::class, 1);
    }

    public function test_a_soft_deleted_appointment_keeps_its_photos(): void
    {
        $appointment = Event::factory()->create();
        $photo = $this->photoOn($appointment);

        $appointment->delete();

        $this->assertSoftDeleted($appointment);
        $this->assertPhotosKept([$photo]);
    }

    /** A product, an event type and a check announce nothing, but take records with photos along. */
    public function test_the_nightly_run_clears_what_was_deleted_without_a_signal(): void
    {
        $product = Product::factory()->create();
        $event_type = EventType::factory()->create();
        $check = ServiceCheck::factory()->create();
        $photos = [
            $this->photoOn($product),
            $this->photoOn($this->machine($product)),
            $this->photoOn(Event::factory()->create(['event_type_id' => $event_type->id])),
            $this->photoOn($this->checkOn($this->jobOn($this->order()), $check)),
        ];

        $product->delete();
        $event_type->delete();
        $check->delete();
        DeleteOrphanedImagesJob::dispatchSync();

        $this->assertPhotosGone($photos);
    }

    public function test_the_nightly_run_is_scheduled(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('delete-orphaned-images', Artisan::output());
    }

    public function test_the_photos_of_other_records_stay(): void
    {
        $order = $this->order();
        $this->photoOn($order);
        $kept = [
            $this->photoOn($this->order()),
            $this->photoOn($this->checkOn($this->jobOn($this->order()))),
            $this->photoOn(Product::factory()->create()),
        ];

        $this->actingAs($this->admin())->delete(route('serviceorders.destroy', $order));

        $this->assertPhotosKept($kept);
    }

    /** Deleting it anyway would not merely fail: the whole run would stop, and the photo after it stay. */
    public function test_a_photo_still_linked_to_another_record_stays_there(): void
    {
        $order = $this->order();
        $product = Product::factory()->create();
        $shared = $this->photoOn($order);
        $product->images()->attach($shared->id);
        $own = $this->photoOn($order);

        $this->actingAs($this->admin())->delete(route('serviceorders.destroy', $order));

        $this->assertTrue($product->images()->whereKey($shared->id)->exists());
        $this->assertPhotosKept([$shared]);
        $this->assertPhotosGone([$own]);
    }

    public function test_a_deletion_that_rolls_back_keeps_its_photos(): void
    {
        $order = $this->order();
        $photo = $this->photoOn($order);

        rescue(fn () => DB::transaction(function () use ($order) {
            $order->delete();

            throw new RuntimeException('Teruggedraaid');
        }), report: false);

        $this->assertModelExists($order);
        $this->assertPhotosKept([$photo]);
    }

    /** Deleting a werkbon used to take the links and keep the photos. */
    public function test_old_photos_without_a_link_are_cleared_but_a_fresh_upload_is_not(): void
    {
        $old = $this->unlinkedPhoto(now()->subDays(2));
        $fresh = $this->unlinkedPhoto(now());

        DeleteOrphanedImagesJob::dispatchSync();

        $this->assertPhotosGone([$old]);
        $this->assertPhotosKept([$fresh]);
    }

    public function test_links_of_a_class_that_no_longer_exists_are_left_alone(): void
    {
        $photo = $this->photoOn(Product::factory()->create());
        DB::table('imageables')->where('image_id', $photo->id)->update(['imageable_type' => 'App\\Models\\Verdwenen']);

        DeleteOrphanedImagesJob::dispatchSync();

        $this->assertPhotosKept([$photo]);
    }

    public function test_a_queue_that_cannot_be_reached_does_not_fail_the_deletion(): void
    {
        Log::spy();
        $order = $this->order();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('Wachtrij onbereikbaar'));

        $this->actingAs($this->admin())
            ->delete(route('serviceorders.destroy', $order))
            ->assertRedirect();

        $this->assertModelMissing($order);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'opruimen'));
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function machine(?Product $product = null): Asset
    {
        return Asset::factory()->create([
            'product_id' => $product?->id ?? Product::factory()->create()->id,
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    private function jobOn(ServiceOrder $order, ?Asset $machine = null, array $attributes = []): ServiceJob
    {
        return ServiceJob::factory()->create([
            'service_order_id' => $order->id,
            'asset_id' => ($machine ?? $this->machine())->id,
            ...$attributes,
        ]);
    }

    private function checkOn(ServiceJob $job, ?ServiceCheck $check = null): ServiceCheckInstance
    {
        return ServiceCheckInstance::create([
            'service_check_id' => ($check ?? ServiceCheck::factory()->create())->id,
            'service_job_id' => $job->id,
        ]);
    }

    private function unlinkedPhoto(\DateTimeInterface $created_at): Image
    {
        $path = 'uploaded/los/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($path, 'photo');

        return Image::create(['name' => 'Foto', 'path' => $path, 'created_at' => $created_at]);
    }

    /** @param  array<int, Image>  $photos */
    private function assertPhotosGone(array $photos): void
    {
        foreach ($photos as $photo) {
            $this->assertModelMissing($photo);
            Storage::disk('public')->assertMissing($photo->path);
        }
    }

    /** @param  array<int, Image>  $photos */
    private function assertPhotosKept(array $photos): void
    {
        foreach ($photos as $photo) {
            $this->assertModelExists($photo);
            Storage::disk('public')->assertExists($photo->path);
        }
    }
}
