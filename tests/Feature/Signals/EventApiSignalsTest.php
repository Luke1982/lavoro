<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\ServiceOrders\ServiceOrderAssigned;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Notifications\NewServiceOrderAssigned;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The appointment API used to notify assigned users from three separate places
 * with subtly different rules. These cover that it now announces instead, and
 * that only genuinely new people are told.
 */
class EventApiSignalsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    private function eventType(): EventType
    {
        return EventType::create(['name' => 'Service', 'color' => '#ffffff']);
    }

    /** A user with no push token resolves to no channels, so notifications need one. */
    private function notifiableUser(): User
    {
        $user = User::factory()->create();
        DeviceToken::create([
            'user_id' => $user->id,
            'token' => 'token-' . $user->id,
            'platform' => 'android',
        ]);

        return $user;
    }

    public function test_creating_an_appointment_with_executors_announces_the_assignment(): void
    {
        Notification::fake();

        $order = $this->order();
        $technician = $this->notifiableUser();

        $this->actingAs($this->admin())->postJson(route('events.store'), [
            'name' => 'Onderhoud',
            'event_type_id' => $this->eventType()->id,
            'status' => 'Gepland',
            'start' => now()->addDay()->format('Y-m-d H:i'),
            'end' => now()->addDay()->addHour()->format('Y-m-d H:i'),
            'eventable_type' => '\\App\\Models\\ServiceOrder',
            'eventable_id' => $order->id,
            'executing_user_ids' => [$technician->id],
        ])->assertCreated();

        Notification::assertSentTo($technician, NewServiceOrderAssigned::class);
    }

    public function test_reassigning_only_notifies_people_who_were_not_already_on_it(): void
    {
        $order = $this->order();
        $existing = $this->notifiableUser();
        $newcomer = $this->notifiableUser();

        $order->syncExecutingUsers([$existing->id]);

        $event = Event::create([
            'name' => 'Onderhoud',
            'event_type_id' => $this->eventType()->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);
        $event->serviceOrders()->attach($order->id);

        Notification::fake();

        $this->actingAs($this->admin())->putJson(route('events.update', $event), [
            'name' => 'Onderhoud',
            'event_type_id' => $event->event_type_id,
            'status' => 'Gepland',
            'start' => $event->start->format('Y-m-d H:i'),
            'end' => $event->end->format('Y-m-d H:i'),
            'executing_user_ids' => [$existing->id, $newcomer->id],
        ]);

        Notification::assertSentTo($newcomer, NewServiceOrderAssigned::class);
        Notification::assertNotSentTo($existing, NewServiceOrderAssigned::class);
    }

    public function test_the_assignment_signal_carries_only_the_new_people(): void
    {
        \Illuminate\Support\Facades\Event::fake([ServiceOrderAssigned::class]);

        $order = $this->order();
        $technician = $this->notifiableUser();

        $this->actingAs($this->admin())->postJson(route('events.store'), [
            'name' => 'Onderhoud',
            'event_type_id' => $this->eventType()->id,
            'status' => 'Gepland',
            'start' => now()->addDay()->format('Y-m-d H:i'),
            'end' => now()->addDay()->addHour()->format('Y-m-d H:i'),
            'eventable_type' => '\\App\\Models\\ServiceOrder',
            'eventable_id' => $order->id,
            'executing_user_ids' => [$technician->id],
        ]);

        \Illuminate\Support\Facades\Event::assertDispatched(
            ServiceOrderAssigned::class,
            fn (ServiceOrderAssigned $signal) => $signal->newly_assigned_user_ids === [$technician->id]
        );
    }
}
