<?php

namespace App\Actions\Appointments;

use App\Domain\Signals\ServiceOrders\ServiceOrderAssigned;
use App\Domain\Signals\Signals;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

/**
 * Schedules an appointment: the event itself, the werkbon it hangs off, and the
 * people executing it, as one atomic act.
 *
 * The assignment signal is raised after the transaction commits, because a
 * technician should never be notified about work that was rolled back.
 */
class CreateAppointmentAction
{
    public function execute(NewAppointment $appointment): Event
    {
        $notify_service_order = null;
        $notify_user_ids = [];

        $event = DB::transaction(function () use ($appointment, &$notify_service_order, &$notify_user_ids) {
            $eventable_type = $appointment->eventable_type;
            $eventable_id = $appointment->eventable_id;

            if ($appointment->create_service_order) {
                $eventable_type = '\\' . ServiceOrder::class;
                $eventable_id = ServiceOrder::create(['customer_id' => $appointment->customer_id])->id;
            }

            $attributes = $appointment->attributes;
            $attributes['eventable_type'] = $appointment->no_service_order ? null : $eventable_type;
            $attributes['eventable_id'] = $appointment->no_service_order ? null : $eventable_id;

            $event = Event::create($attributes);

            $model = null;

            if (!$appointment->no_service_order) {
                $model = $eventable_type::findOrFail($eventable_id);
                $model->events()->attach($event->id);

                if ($model instanceof ServiceOrder) {
                    $model->advanceToPlannedStage();
                }
            } elseif ($appointment->customer_id) {
                $event->customers()->attach($appointment->customer_id);
            }

            $assignment = $appointment->assignment;

            if ($assignment && !$assignment->isEmpty()) {
                $event->syncExecutingUsers(
                    $assignment->user_ids,
                    $assignment->breaktimes,
                    $assignment->user_roles,
                    $assignment->diverging_times,
                );

                if ($model) {
                    $model->syncExecutingUsers($assignment->user_ids);
                    $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($assignment->user_ids));

                    if ($model instanceof ServiceOrder) {
                        $notify_service_order = $model;
                        $notify_user_ids = $assignment->user_ids;
                    }
                }
            }

            return $event;
        });

        if ($notify_service_order) {
            Signals::dispatch(new ServiceOrderAssigned($notify_service_order, $notify_user_ids));
        }

        return $event;
    }
}
