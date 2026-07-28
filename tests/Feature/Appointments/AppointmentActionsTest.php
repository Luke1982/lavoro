<?php

namespace Tests\Feature\Appointments;

use App\Actions\Appointments\AppointmentChanges;
use App\Actions\Appointments\CancelAppointmentAction;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Actions\Appointments\UpdateAppointmentAction;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The planner, the API and a tool call all schedule through these actions, so
 * what they guarantee has to hold whoever is calling.
 *
 * The pair that matters most is the difference between saying nothing about the
 * werkbon and saying it should be empty. A drag across the calendar omits the
 * key entirely and must leave the werkbon alone; clearing the field is the only
 * way to take one off, and it must actually take it off.
 */
class AppointmentActionsTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'event_type_id' => EventType::factory()->create()->id,
            'status' => 'Gepland',
            'start' => now()->addDay()->format('Y-m-d H:i'),
            'end' => now()->addDay()->addHour()->format('Y-m-d H:i'),
        ];
    }

    private function create(array $context = []): Event
    {
        $attributes = $this->attributes();

        return app(CreateAppointmentAction::class)->execute(
            NewAppointment::fromPayload($attributes, $attributes + $context)
        );
    }

    private function technician(): User
    {
        return $this->userWith('serviceorder.read_own');
    }

    public function test_an_appointment_can_be_hung_on_an_existing_werkbon(): void
    {
        $order = $this->order();
        $user = $this->technician();

        $event = $this->create([
            'eventable_type' => '\\' . ServiceOrder::class,
            'eventable_id' => $order->id,
            'executing_user_ids' => [$user->id],
        ]);

        $this->assertTrue($event->serviceOrders()->where('service_orders.id', $order->id)->exists());
        $this->assertSame(1, $event->executingUsers()->count());
    }

    public function test_asking_for_a_new_werkbon_creates_one_for_the_customer(): void
    {
        $customer = Customer::factory()->create();

        $event = $this->create([
            'create_service_order' => true,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$this->technician()->id],
        ]);

        $order = $event->serviceOrders()->sole();

        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_an_appointment_without_a_werkbon_is_attached_to_the_customer_instead(): void
    {
        $customer = Customer::factory()->create();

        $event = $this->create([
            'no_service_order' => true,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$this->technician()->id],
        ]);

        $this->assertSame(0, $event->serviceOrders()->count());
        $this->assertTrue($event->customers()->where('customers.id', $customer->id)->exists());
    }

    /**
     * Dragging an appointment to another day sends only its times. Reading that
     * as "no werkbon wanted" would quietly unlink the job it was booked for.
     */
    public function test_rescheduling_without_mentioning_the_werkbon_leaves_it_attached(): void
    {
        $order = $this->order();
        $event = $this->create([
            'eventable_type' => '\\' . ServiceOrder::class,
            'eventable_id' => $order->id,
            'executing_user_ids' => [$this->technician()->id],
        ]);

        app(UpdateAppointmentAction::class)->execute($event, AppointmentChanges::fromPayload(
            [
                'start' => now()->addDays(3)->format('Y-m-d H:i'),
                'end' => now()->addDays(3)->addHour()->format('Y-m-d H:i'),
            ],
            ['eventable_provided' => false],
        ));

        $this->assertTrue(
            $event->fresh()->serviceOrders()->where('service_orders.id', $order->id)->exists(),
            'a reschedule detached the werkbon'
        );
    }

    public function test_clearing_the_werkbon_on_purpose_does_detach_it(): void
    {
        $order = $this->order();
        $event = $this->create([
            'eventable_type' => '\\' . ServiceOrder::class,
            'eventable_id' => $order->id,
            'executing_user_ids' => [$this->technician()->id],
        ]);

        app(UpdateAppointmentAction::class)->execute($event, AppointmentChanges::fromPayload(
            [],
            ['eventable_provided' => true, 'eventable_id' => null],
        ));

        $fresh = $event->fresh();

        $this->assertSame(0, $fresh->serviceOrders()->count());
        $this->assertTrue((bool) $fresh->no_service_order);
    }

    public function test_saying_nothing_about_the_crew_leaves_the_crew_alone(): void
    {
        $user = $this->technician();
        $order = $this->order();
        $event = $this->create([
            'eventable_type' => '\\' . ServiceOrder::class,
            'eventable_id' => $order->id,
            'executing_user_ids' => [$user->id],
        ]);

        app(UpdateAppointmentAction::class)->execute($event, AppointmentChanges::fromPayload(
            ['name' => 'andere naam'],
            [],
        ));

        $this->assertSame(1, $event->fresh()->executingUsers()->count());
    }

    public function test_cancelling_removes_the_appointment(): void
    {
        $event = $this->create([
            'no_service_order' => true,
            'executing_user_ids' => [$this->technician()->id],
        ]);

        app(CancelAppointmentAction::class)->execute($event);

        $this->assertNull(Event::find($event->id));
    }
}
