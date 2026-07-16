<?php

namespace App\Http\Requests\Concerns;

use App\Models\ServiceOrder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared location handling for the event store/update requests.
 */
trait ResolvesEventLocation
{
    /**
     * A linked location is the source of truth for where the appointment is, so
     * a location_id and a free-text location can never coexist.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('location_id')) {
            $this->merge(['location' => null]);
        }
    }

    /**
     * A picked location must belong to the customer this appointment is for.
     * Always scoped: when no customer can be resolved, 0 matches nothing and the
     * location_id is rejected rather than waved through.
     */
    private function locationRule(): Exists
    {
        $customer_id = $this->resolvedCustomerId() ?? 0;

        return Rule::exists('locations', 'id')
            ->where(fn ($q) => $q->where('customer_id', $customer_id));
    }

    /**
     * The customer behind this appointment: sent along, behind the werkbon being
     * linked, or behind the event being updated.
     */
    private function resolvedCustomerId(): ?int
    {
        if ($this->filled('customer_id')) {
            return (int) $this->input('customer_id');
        }

        $order_id = $this->input('eventable_id');
        if ($order_id) {
            return ServiceOrder::find($order_id)?->customer_id;
        }

        $event = $this->route('event');

        return $event?->serviceOrders()->first()?->customer_id
            ?? $event?->customers()->first()?->id;
    }
}
