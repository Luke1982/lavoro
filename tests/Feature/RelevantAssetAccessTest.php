<?php

namespace Tests\Feature;

use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A monteur who may not read machines at large still has to open the machine of
 * the keuring in front of them. Which werkbonnen are theirs is the same rule that
 * decides which werkbonnen they may open: on the werkbon itself, or on one of its
 * appointments.
 *
 * The machine used to follow only the first half. Planning a second appointment
 * with somebody else replaces the werkbon's own list, so the first monteur could
 * still open the werkbon and the keuring and was refused the machine on it.
 *
 * A refusal arrives as a redirect carrying an 'error' message, not a bare 403:
 * that is what bootstrap/app.php makes of an AuthorizationException on a page.
 */
class RelevantAssetAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function monteur(): User
    {
        return $this->userWithPermissions('asset.read.relevant.serviceorder', 'serviceorder.read_own');
    }

    /** @return array{0: ServiceOrder, 1: Asset} */
    private function werkbon_with_keuring(array $order_attributes = []): array
    {
        $customer = Customer::factory()->create();
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => Product::factory()->create()->id,
        ]);
        $order = ServiceOrder::factory()->create(array_merge(['customer_id' => $customer->id], $order_attributes));

        ServiceJob::factory()->create([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
        ]);

        return [$order, $asset];
    }

    private function plan(ServiceOrder $order, User $user): Event
    {
        $attributes = [
            'event_type_id' => EventType::factory()->create()->id,
            'status' => 'Gepland',
            'start' => now()->addDay()->format('Y-m-d H:i'),
            'end' => now()->addDay()->addHour()->format('Y-m-d H:i'),
        ];

        return app(CreateAppointmentAction::class)->execute(NewAppointment::fromPayload($attributes, $attributes + [
            'eventable_type' => '\\' . ServiceOrder::class,
            'eventable_id' => $order->id,
            'executing_user_ids' => [$user->id],
        ]));
    }

    public function test_a_monteur_on_the_werkbon_opens_the_machine_of_its_keuring(): void
    {
        $user = $this->monteur();
        [$order, $asset] = $this->werkbon_with_keuring();
        $order->syncExecutingUsers([$user->id]);

        $this->actingAs($user)->get("/assets/{$asset->id}")->assertOk();
    }

    public function test_a_monteur_on_an_appointment_of_the_werkbon_opens_the_machine_of_its_keuring(): void
    {
        $user = $this->monteur();
        [$order, $asset] = $this->werkbon_with_keuring();
        $event = Event::factory()->create();
        $order->events()->attach($event->id);
        $event->syncExecutingUsers([$user->id]);

        $this->actingAs($user)->get("/assets/{$asset->id}")->assertOk();
    }

    /**
     * The way it actually went wrong, through the actions the planner uses: the
     * second appointment takes the first monteur off the werkbon's own list, while
     * their own appointment still sends them there.
     */
    public function test_a_second_appointment_with_somebody_else_does_not_lock_the_first_monteur_out(): void
    {
        $first = $this->monteur();
        $second = $this->monteur();
        [$order, $asset] = $this->werkbon_with_keuring();

        $this->plan($order, $first);
        $this->plan($order, $second);

        $this->actingAs($first)->get("/assets/{$asset->id}")->assertOk();
        $this->actingAs($second)->get("/assets/{$asset->id}")->assertOk();
    }

    public function test_the_machine_of_somebody_elses_werkbon_is_refused(): void
    {
        $user = $this->monteur();
        [$order, $asset] = $this->werkbon_with_keuring();
        $this->plan($order, $this->monteur());

        $this->actingAs($user)->get("/assets/{$asset->id}")->assertSessionHas('error');
    }

    public function test_the_machine_of_a_closed_werkbon_is_refused(): void
    {
        $user = $this->monteur();
        $closed = ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 99, 'is_closed_state' => true]);
        [$order, $asset] = $this->werkbon_with_keuring(['service_order_stage_id' => $closed->id]);
        $this->plan($order, $user);

        $this->actingAs($user)->get("/assets/{$asset->id}")->assertSessionHas('error');
    }
}
