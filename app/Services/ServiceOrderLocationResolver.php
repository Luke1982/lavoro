<?php

namespace App\Services;

use App\Models\ServiceOrder;
use App\Support\AddressFormatter;

/**
 * Where a werkbon happens, for anything that shows it to a user.
 *
 * ServiceOrder::locationWithSource() walks the order's own escalation — linked
 * location, free-text execution location, project location — and stops there.
 * The customer's own address is the last resort that only a display has any
 * business falling back to, so it lives here, in one place, for every display.
 */
class ServiceOrderLocationResolver
{
    /**
     * Every relation resolve() walks, to spread into with()/load() so the
     * escalation can't N+1. The linked location is missing on purpose:
     * ServiceOrder::$with already loads it.
     */
    public static function relations(string $prefix = ''): array
    {
        return array_map(fn ($relation) => $prefix . $relation, ['customer', 'project']);
    }

    /**
     * @return array{address: ?string, source: ?string}
     */
    public static function resolve(ServiceOrder $service_order): array
    {
        $location = $service_order->locationWithSource();

        if ($location['address'] || !$service_order->customer) {
            return $location;
        }

        $customer_address = AddressFormatter::format(
            $service_order->customer->address,
            $service_order->customer->postal_code,
            $service_order->customer->city,
        );

        return $customer_address
            ? ['address' => $customer_address, 'source' => 'customer']
            : ['address' => null, 'source' => null];
    }

    /**
     * The city of wherever resolve() landed. A location and a customer both carry
     * a city column, so they answer for themselves — an empty one means the
     * address genuinely has no city, not an invitation to go parse its street out
     * of the address line. Only free text, which has no columns to ask, is parsed.
     */
    public static function city(ServiceOrder $service_order): ?string
    {
        $location = self::resolve($service_order);

        return match ($location['source']) {
            'location' => trim((string) $service_order->linkedLocation?->city) ?: null,
            'customer' => trim((string) $service_order->customer?->city) ?: null,
            default => AddressFormatter::city($location['address']),
        };
    }
}
