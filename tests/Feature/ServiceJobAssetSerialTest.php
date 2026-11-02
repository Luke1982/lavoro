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
 * A refusal arrives as a redirect carrying an 'error' message, not as a bare
 * 403: that is what bootstrap/app.php makes of an AuthorizationException on a
 * request that wants a page back.
 */
class ServiceJobAssetSerialTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function job(?User $executing_user = null): ServiceJob
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['bundle' => false, 'registable' => true]);
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
            ->assertSessionHas('error');

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

    public function test_the_serial_is_the_only_thing_this_route_accepts(): void
    {
        $user = $this->userWith('asset.set_serial.relevant.serviceorder');
        $job = $this->job($user);

        $this->actingAs($user)
            ->patch("/servicejobs/{$job->id}/serial", ['serial_number' => ''])
            ->assertSessionHasErrors('serial_number');

        $this->assertSame('OUD-1', $job->asset->refresh()->serial_number);
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
