<?php

namespace Tests\Feature\Images;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\AttachesPhotos;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Deleting a werkbon took the links to its photos along, but left the photos and
 * their files behind for good. The photos of its checks too: the database cascades
 * those checks away without a model event.
 */
class DeletedServiceOrdersTakeTheirPhotosTest extends TestCase
{
    use AttachesPhotos;
    use CreatesAuthenticatedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_photos_of_the_order_and_of_its_checks_go_with_it(): void
    {
        $order = $this->order();
        $photos = [
            $this->photoOn($order),
            $this->photoOn($order, pivot: ['internal' => true]),
            $this->photoOn($this->checkOn($order)),
        ];

        $this->deleteOrder($order);

        foreach ($photos as $photo) {
            $this->assertModelMissing($photo);
            Storage::disk('public')->assertMissing($photo->path);
        }
        $this->assertDatabaseCount('imageables', 0);
    }

    public function test_the_photos_of_other_records_stay(): void
    {
        $order = $this->order();
        $this->photoOn($order);
        $kept = [
            $this->photoOn($this->order()),
            $this->photoOn($this->checkOn($this->order())),
            $this->photoOn(Product::factory()->create()),
        ];

        $this->deleteOrder($order);

        foreach ($kept as $photo) {
            $this->assertModelExists($photo);
            Storage::disk('public')->assertExists($photo->path);
        }
    }

    public function test_a_photo_also_linked_to_another_record_stays_there(): void
    {
        $order = $this->order();
        $product = Product::factory()->create();
        $photo = $this->photoOn($order);
        $product->images()->attach($photo->id);

        $this->deleteOrder($order);

        $this->assertTrue($product->images()->whereKey($photo->id)->exists());
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_a_deletion_that_rolls_back_keeps_its_files(): void
    {
        $order = $this->order();
        $photo = $this->photoOn($order);

        rescue(fn () => DB::transaction(function () use ($order) {
            $order->delete();

            throw new RuntimeException('Teruggedraaid');
        }), report: false);

        $this->assertModelExists($order);
        $this->assertModelExists($photo);
        Storage::disk('public')->assertExists($photo->path);
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function checkOn(ServiceOrder $order): ServiceCheckInstance
    {
        $asset = Asset::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'customer_id' => $order->customer_id,
        ]);
        $job = ServiceJob::factory()->create(['service_order_id' => $order->id, 'asset_id' => $asset->id]);

        return ServiceCheckInstance::create([
            'service_check_id' => ServiceCheck::factory()->create()->id,
            'service_job_id' => $job->id,
        ]);
    }

    private function deleteOrder(ServiceOrder $order): void
    {
        $this->actingAs($this->admin())
            ->delete(route('serviceorders.destroy', $order))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($order);
    }
}
