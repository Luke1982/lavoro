<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Filling in a machine's serial from the keuring itself. That is where the plate
 * is in front of the monteur, and a monteur may touch nothing else on the
 * machine's record.
 *
 * A refusal arrives as a redirect carrying the reason it was given in an
 * 'error' message, not as a bare 403: that is what bootstrap/app.php makes of
 * an AuthorizationException on a request that wants a page back.
 */
class ServiceJobAssetSerialTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function job(?User $executing_user = null, array $product_attributes = []): ServiceJob
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(array_merge(
            ['bundle' => false, 'registable' => true],
            $product_attributes,
        ));
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => 'OUD-1',
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        if ($executing_user !== null) {
            $order->syncExecutingUsers([$executing_user->id]);
        }

        return ServiceJob::factory()->create([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
        ]);
    }

    public function test_a_monteur_may_fill_in_the_serial_of_a_machine_on_their_own_werkbon(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job($user);

        $this->actingAs($user)
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'NIEUW-9'])
            ->assertRedirect();

        $this->assertSame('NIEUW-9', $job->asset->refresh()->serial_number);
    }

    public function test_the_same_right_does_nothing_on_somebody_elses_werkbon(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job();

        $this->actingAs($user)
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'NIEUW-9'])
            ->assertSessionHas('error', 'Je mag het serienummer van deze machine niet invullen.');

        $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
    }

    public function test_whoever_may_edit_machines_may_fill_in_the_serial_anywhere(): void
    {
        $job = $this->job();

        $this->actingAs($this->userWith('asset.update'))
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'NIEUW-9'])
            ->assertRedirect();

        $this->assertSame('NIEUW-9', $job->asset->refresh()->serial_number);
    }

    public function test_a_user_with_neither_right_is_refused(): void
    {
        $job = $this->job();

        $this->actingAs(User::factory()->create())
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'NIEUW-9'])
            ->assertSessionHas('error');

        $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
    }

    public function test_an_empty_serial_is_refused(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job($user);

        $this->actingAs($user)
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => ''])
            ->assertSessionHasErrors('serial_number');

        $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
    }

    public function test_a_serial_another_machine_of_the_same_product_has_is_refused(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job($user);
        Asset::factory()->create([
            'customer_id' => $job->asset->customer_id,
            'product_id' => $job->asset->product_id,
            'serial_number' => 'BEZET-7',
        ]);

        $this->actingAs($user)
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'BEZET-7'])
            ->assertSessionHasErrors('serial_number');

        $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
    }

    public function test_nothing_but_the_serial_comes_through(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job($user);
        $own_customer_id = $job->asset->customer_id;
        $own_status = $job->asset->status;

        $this->actingAs($user)->patch("/servicejobs/{$job->id}/serial", [
            'serial_number' => 'NIEUW-9',
            'customer_id' => Customer::factory()->create()->id,
            'status' => 'ingenomen',
        ]);

        $asset = $job->asset->refresh();

        $this->assertSame('NIEUW-9', $asset->serial_number);
        $this->assertSame($own_customer_id, $asset->customer_id);
        $this->assertSame($own_status, $asset->status);
    }

    /**
     * A machine of a product that is counted per piece never has a serial, and a
     * bundle is an envelope around the parts that do. Nobody fills one in, not
     * even whoever may edit every machine there is.
     */
    public function test_a_machine_that_cannot_have_a_serial_is_refused(): void
    {
        $user = $this->userWith('asset.update');

        foreach ([['registable' => false], ['bundle' => true]] as $product_attributes) {
            $job = $this->job(product_attributes: $product_attributes);

            $this->actingAs($user)
                ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => 'NIEUW-9'])
                ->assertSessionHas('error', 'Deze machine heeft geen serienummer.');

            $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
        }
    }

    public function test_the_keuring_page_says_whether_the_serial_may_be_filled_in(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $mine = $this->job($user);
        $theirs = $this->job();

        $this->actingAs($user)
            ->get("/servicejobs/{$mine->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_set_serial', true));

        $this->actingAs($user)
            ->get("/servicejobs/{$theirs->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_set_serial', false));
    }
}
