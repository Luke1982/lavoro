<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;

/**
 * One rounded picture of a customer, so the assistant does not have to make four
 * calls and stitch the answers together.
 *
 * The werkbon and machine counts run through the same visibility scopes as the
 * dedicated tools, so a monteur gets a summary of what they are allowed to see
 * rather than of everything that exists.
 */
class SummarizeCustomerTool implements Tool
{
    public static function name(): string
    {
        return 'summarize_customer';
    }

    public function description(): string
    {
        return 'Geeft een overzicht van één klant: contactgegevens, openstaande werkbonnen, '
            . 'machines en het onderhoud dat eraan komt. Gebruik dit als iemand vraagt hoe een klant ervoor staat, '
            . 'in plaats van losse zoekopdrachten te combineren.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Het id van de klant, te vinden met find_customer.',
                ],
            ],
            'required' => ['customer_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('list', Customer::class);
    }

    /** Eén argument, maar het vraagt wel te zien dat een overzicht gevraagd wordt in plaats van een losse zoekopdracht. */
    public static function difficulty(): int
    {
        return 3;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $customer = Customer::find($call->integerArgument('customer_id'));

        if (!$customer) {
            return ToolResult::notFound('Klant');
        }

        $open_orders = ServiceOrder::query()
            ->visibleTo($call->user)
            ->where('customer_id', $customer->id)
            ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->where('is_closed_state', true))
            ->with('serviceOrderStage:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $assets = Asset::query()
            ->visibleTo($call->user)
            ->where('customer_id', $customer->id)
            ->with(['product:id,brand_id,model', 'product.brand:id,name'])
            ->orderBy('next_service_date')
            ->limit(10)
            ->get();

        return ToolResult::ok([
            'customer' => [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'address' => trim($customer->address . ' ' . $customer->postal_code . ' ' . $customer->city),
                'email' => $customer->email,
                'phone' => $customer->phone ?: $customer->mobile,
                'contact' => $customer->contactname,
            ],
            'open_service_orders' => $open_orders->map(fn (ServiceOrder $order) => [
                'service_order_id' => $order->id,
                'description' => $order->description,
                'stage' => $order->serviceOrderStage?->name,
            ])->all(),
            'open_service_order_count' => $open_orders->count(),
            'assets' => $assets->map(fn (Asset $asset) => [
                'asset_id' => $asset->id,
                'serial_number' => $asset->serial_number,
                'product' => $asset->product?->display_name,
                'next_service_date' => $asset->next_service_date,
            ])->all(),
        ], 'Overzicht van ' . $customer->name . '.');
    }
}
