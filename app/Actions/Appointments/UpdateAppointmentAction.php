<?php

namespace App\Actions\Appointments;

use App\Domain\Signals\Appointments\AppointmentServiceOrderChanged;
use App\Domain\Signals\Appointments\AppointmentUnassigned;
use App\Domain\Signals\ServiceOrders\ServiceOrderAssigned;
use App\Domain\Signals\ServiceOrders\ServiceOrderScheduled;
use App\Domain\Signals\ServiceOrders\ServiceOrderUnscheduled;
use App\Domain\Signals\Signals;
use App\Domain\Signals\Users\NameListChange;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a change to an existing appointment: its own fields, the werkbon it
 * is attached to, and the people executing it.
 *
 * The three concerns are separate on purpose. A caller may move an appointment
 * in time without touching its werkbon, swap its werkbon without touching its
 * crew, or reassign it without touching either, and each of those has to leave
 * the other two exactly as they were.
 */
class UpdateAppointmentAction
{
    public function execute(Event $event, AppointmentChanges $changes): Event
    {
        /**
         * Vastgelegd voordat de afspraak verschuift: wie er in dezelfde handeling
         * af gehaald wordt, kent de afspraak op dit moment en op geen ander.
         */
        $known_start = $event->start?->copy();

        $event->update($changes->attributes);

        $eventable_type = $changes->create_service_order
            ? '\\' . ServiceOrder::class
            : $changes->eventable_type;

        $eventable_id = $changes->create_service_order ? null : $changes->eventable_id;
        $linking = $changes->create_service_order || ($eventable_type && $eventable_id);

        if ($event->no_service_order && !$linking) {
            $event->syncCustomers([$changes->customer_id]);
        }

        $model = $linking
            ? $this->link($event, $changes, $eventable_type, $eventable_id)
            : null;

        if (!$linking && $changes->eventable_provided && $event->serviceOrders()->exists()) {
            $this->unlink($event, $changes);
        }

        if ($changes->assignment) {
            $this->assign($event, $changes->assignment, $model, $known_start);
        }

        return $event;
    }

    private function link(Event $event, AppointmentChanges $changes, string $eventable_type, ?int $eventable_id): Model
    {
        return DB::transaction(function () use ($event, $changes, $eventable_type, $eventable_id) {
            if ($changes->create_service_order) {
                $eventable_id = ServiceOrder::create(['customer_id' => $changes->customer_id])->id;
            }

            $model = $eventable_type::findOrFail($eventable_id);

            if (
                $changes->service_order_customer_action === 'move'
                && $model instanceof ServiceOrder
                && $changes->customer_id
                && $model->customer_id !== $changes->customer_id
            ) {
                $model->moveToCustomer($changes->customer_id);
            }

            $linked_before = $event->serviceOrders()->get();

            DB::table('eventables')
                ->where('event_id', $event->id)
                ->where('eventable_type', substr($eventable_type, 1))
                ->delete();

            $model->events()->attach($event->id);

            if ($model instanceof ServiceOrder) {
                $model->advanceToPlannedStage();
                $this->announceMove($event, $linked_before, $model);
            }

            if ($event->no_service_order) {
                $event->syncCustomers([], $model instanceof ServiceOrder ? $model : null);
                $event->update(['no_service_order' => false]);
            }

            return $model;
        });
    }

    private function unlink(Event $event, AppointmentChanges $changes): void
    {
        DB::transaction(function () use ($event, $changes) {
            $linked_before = $event->serviceOrders;

            foreach ($linked_before as $service_order) {
                $service_order->revertToPlanningCancelledStage();
            }

            $event->serviceOrders()->detach();
            $event->update(['no_service_order' => true]);
            $event->syncCustomers([$changes->customer_id]);

            $this->announceMove($event, $linked_before, null);
        });
    }

    /**
     * Only what changed: every werkbon the appointment left, and the one it came
     * onto when it was not on it already. Announced once the links are written,
     * so a listener asking a werkbon for its appointments gets today's answer.
     *
     * @param  Collection<int, ServiceOrder>  $linked_before
     */
    private function announceMove(Event $event, Collection $linked_before, ?ServiceOrder $linked_now): void
    {
        $label = fn (ServiceOrder $service_order) => 'werkbon ' . $service_order->number;
        $service_orders = new NameListChange(
            $linked_before->mapWithKeys(fn (ServiceOrder $service_order) => [$service_order->id => $label($service_order)])->all(),
            $linked_now ? [$linked_now->id => $label($linked_now)] : [],
        );

        if (!$service_orders->isEmpty()) {
            Signals::dispatch(new AppointmentServiceOrderChanged($event, $service_orders));
        }

        $linked_before
            ->reject(fn (ServiceOrder $service_order) => $service_order->is($linked_now))
            ->each(fn (ServiceOrder $service_order) => Signals::dispatch(
                new ServiceOrderUnscheduled($service_order, $event)
            ));

        if ($linked_now && !$linked_before->contains($linked_now)) {
            Signals::dispatch(new ServiceOrderScheduled($linked_now, $event));
        }
    }

    private function assign(
        Event $event,
        AppointmentAssignment $assignment,
        ?Model $model,
        ?Carbon $known_start = null
    ): void {
        $departed = array_values(array_diff(
            $event->executingUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
            array_map('intval', $assignment->user_ids),
        ));

        $event->syncExecutingUsers(
            $assignment->user_ids,
            $assignment->breaktimes,
            $assignment->user_roles,
            $assignment->diverging_times,
        );

        if ($departed !== []) {
            Signals::dispatch(new AppointmentUnassigned($event, $departed, $known_start));
        }

        if ($model) {
            $previously_executing = $model instanceof ServiceOrder
                ? $model->executingUsers()->pluck('users.id')->all()
                : [];

            $model->syncExecutingUsers($assignment->user_ids);
            $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($assignment->user_ids));

            if ($model instanceof ServiceOrder) {
                Signals::dispatch(new ServiceOrderAssigned(
                    $model,
                    array_values(array_diff($assignment->user_ids, $previously_executing)),
                ));
            }

            return;
        }

        $event->serviceOrders->each(function (ServiceOrder $order) use ($assignment) {
            $previously_executing = $order->executingUsers()->pluck('users.id')->all();

            $order->syncExecutingUsers($assignment->user_ids);
            $order->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($assignment->user_ids));

            Signals::dispatch(new ServiceOrderAssigned(
                $order,
                array_values(array_diff($assignment->user_ids, $previously_executing)),
            ));
        });
    }
}
