<?php

namespace App\Actions\Appointments;

/**
 * A change to an existing appointment.
 *
 * Two of these fields carry intent that a value alone cannot express, and both
 * matter:
 *
 * `eventable_provided` distinguishes "clear the werkbon" from "leave it alone".
 * An explicit but empty eventable_id is the only way to take a werkbon off an
 * appointment; a drag reschedule omits the key entirely and must not unlink.
 *
 * A null `assignment` means the caller said nothing about who is executing,
 * which is not the same as saying nobody.
 */
final class AppointmentChanges
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly array $attributes,
        public readonly ?int $customer_id = null,
        public readonly ?string $service_order_customer_action = null,
        public readonly bool $create_service_order = false,
        public readonly ?string $eventable_type = null,
        public readonly ?int $eventable_id = null,
        public readonly bool $eventable_provided = false,
        public readonly ?AppointmentAssignment $assignment = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated attributes for the event itself.
     * @param  array<string, mixed>  $context  The surrounding intent, unvalidated keys included.
     */
    public static function fromPayload(array $payload, array $context): self
    {
        $customer_id = $payload['customer_id'] ?? null;
        $service_order_customer_action = $payload['service_order_customer_action'] ?? null;

        unset(
            $payload['executing_user_ids'],
            $payload['executing_user_breaktimes'],
            $payload['executing_user_roles'],
            $payload['executing_user_diverging_times'],
            $payload['customer_id'],
            $payload['create_service_order'],
            $payload['service_order_customer_action'],
        );

        $eventable_id = $context['eventable_id'] ?? null;

        return new self(
            attributes: $payload,
            customer_id: $customer_id === null ? null : (int) $customer_id,
            service_order_customer_action: $service_order_customer_action,
            create_service_order: (bool) ($context['create_service_order'] ?? false),
            eventable_type: $context['eventable_type'] ?? null,
            eventable_id: $eventable_id === null ? null : (int) $eventable_id,
            eventable_provided: (bool) ($context['eventable_provided'] ?? false),
            assignment: AppointmentAssignment::fromPayload($context),
        );
    }
}
