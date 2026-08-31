<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\Appointments\AppointmentCancelled;
use App\Jobs\Google\PushEventJob;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The appointment lifecycle is where the observer used to do four unrelated jobs
 * itself. These cover that each subscriber still does its own, independently.
 */
class AppointmentSignalsTest extends TestCase
{

    private function appointment(): Event
    {
        return Event::create([
            'name' => 'Onderhoud',
            'event_type_id' => EventType::create(['name' => 'Service', 'color' => '#fff'])->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);
    }

    public function test_scheduling_an_appointment_pushes_it_to_google(): void
    {
        Queue::fake();

        $appointment = $this->appointment();

        Queue::assertPushed(
            PushEventJob::class,
            fn (PushEventJob $job) => $job->event_id === $appointment->id
        );
    }

    public function test_changing_an_appointment_pushes_it_to_google_again(): void
    {
        $appointment = $this->appointment();

        Queue::fake();
        $appointment->update(['name' => 'Onderhoud gewijzigd']);

        Queue::assertPushed(PushEventJob::class);
    }

    public function test_cancelling_an_appointment_releases_its_werkbonnen_back_to_planning(): void
    {
        /**
         * De gezaaide testtenant heeft zelf al een geannuleerd-fase, en de
         * listener pakt "de" fase met die vlag. Deze test rekent op precies
         * een, dus hij begint leeg; de transactie draait het terug.
         */
        ServiceOrderStage::query()->delete();

        $planned = ServiceOrderStage::create([
            'name' => 'Ingepland', 'order' => 2, 'is_planned_state' => true,
        ]);
        $cancelled = ServiceOrderStage::create([
            'name' => 'Planning geannuleerd', 'order' => 1, 'is_planning_cancelled_state' => true,
        ]);

        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'service_order_stage_id' => $planned->id,
        ]);

        $appointment = $this->appointment();
        $appointment->serviceOrders()->attach($order->id);

        $appointment->delete();

        $this->assertSame($cancelled->id, $order->fresh()->service_order_stage_id);
    }

    public function test_permanently_deleting_an_appointment_does_not_touch_its_werkbonnen(): void
    {
        $planned = ServiceOrderStage::create([
            'name' => 'Ingepland', 'order' => 2, 'is_planned_state' => true,
        ]);
        ServiceOrderStage::create([
            'name' => 'Planning geannuleerd', 'order' => 1, 'is_planning_cancelled_state' => true,
        ]);

        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'service_order_stage_id' => $planned->id,
        ]);

        $appointment = $this->appointment();
        $appointment->serviceOrders()->attach($order->id);

        $appointment->forceDelete();

        $this->assertSame($planned->id, $order->fresh()->service_order_stage_id);
    }

    public function test_a_cancellation_carries_its_google_mappings_so_they_survive_the_delete(): void
    {
        $appointment = $this->appointment();

        $signal = new AppointmentCancelled($appointment);

        $this->assertIsArray($signal->synced_mappings);
    }
}
