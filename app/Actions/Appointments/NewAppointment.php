<?php

namespace App\Actions\Appointments;

/**
 * Everything needed to schedule an appointment, independent of how the request
 * arrived. The web form, the planner drag and a tool call all describe the same
 * intent, so they all describe it with this.
 *
 * `attributes` holds the event's own columns; the rest describes what the
 * appointment is attached to and who is executing it.
 */
final class NewAppointment
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly array $attributes,
        public readonly ?string $eventable_type = null,
        public readonly ?int $eventable_id = null,
        public readonly bool $create_service_order = false,
        public readonly bool $no_service_order = false,
        public readonly ?int $customer_id = null,
        public readonly ?AppointmentAssignment $assignment = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated attributes for the event itself.
     * @param  array<string, mixed>  $context  The surrounding intent, unvalidated keys included.
     */
    public static function fromPayload(array $payload, array $context): self
    {
        unset(
            $payload['executing_user_ids'],
            $payload['executing_user_breaktimes'],
            $payload['executing_user_roles'],
            $payload['executing_user_diverging_times'],
            $payload['create_service_order'],
            $payload['customer_id'],
        );

        $customer_id = $context['customer_id'] ?? null;

        return new self(
            attributes: $payload,
            eventable_type: $context['eventable_type'] ?? null,
            eventable_id: isset($context['eventable_id']) ? (int) $context['eventable_id'] : null,
            create_service_order: (bool) ($context['create_service_order'] ?? false),
            no_service_order: (bool) ($context['no_service_order'] ?? false),
            customer_id: $customer_id === null ? null : (int) $customer_id,
            assignment: AppointmentAssignment::fromPayload($context),
        );
    }
}
