<?php

namespace App\Domain\Tools\Write;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;

/**
 * Opens a werkbon for a customer, with the machines and storingen that belong on it.
 *
 * Everything named has to be the customer's own. A werkbon carrying somebody
 * else's machine is not a validation error anybody notices — it is a mechanic
 * sent to the wrong address holding a job sheet that reads perfectly.
 */
class CreateServiceOrderTool implements Tool
{
    public static function name(): string
    {
        return 'create_service_order';
    }

    public function description(): string
    {
        return 'Maakt een werkbon aan voor een klant, eventueel meteen met machines en storingen erop. '
            . 'Zoek de klant, machines en storingen eerst op zodat je met echte nummers werkt. '
            . 'Roep deze tool aan zodra je de klant hebt: er wordt nog niets aangemaakt, je krijgt terug '
            . 'dat er bevestiging nodig is en het systeem legt de knop aan de gebruiker voor. Vraag dus '
            . 'niet zelf eerst in tekst om toestemming; dan gebeurt er namelijk helemaal niets.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'De klant waarvoor de werkbon is.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Waar de werkbon over gaat.',
                ],
                'asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Machines die op de werkbon komen, elk als taak.',
                ],
                'ticket_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Losse storingen die aan deze werkbon gekoppeld worden.',
                ],
                'location_id' => [
                    'type' => 'integer',
                    'description' => 'Uitvoeringslocatie. Laat weg: staan alle machines op één locatie, '
                        . 'dan wordt die zelf ingevuld.',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Het project waar de werkbon onder valt.',
                ],
            ],
            'required' => ['customer_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', ServiceOrder::class);
    }

    /** Klant, machines en storingen uit een gesprek halen en tot één opdracht samenvoegen. */
    public static function difficulty(): int
    {
        return 6;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $customer_id = $call->integerArgument('customer_id');
        $customer = $customer_id === null ? null : Customer::find($customer_id);

        if ($customer === null) {
            return ToolResult::notFound('Klant #' . ($customer_id ?? '?'));
        }

        $asset_ids = $call->integerListArgument('asset_ids');
        $ticket_ids = $call->integerListArgument('ticket_ids');

        /**
         * Everything has to belong to this customer, and has to be something this
         * person can already see. Either way round, a werkbon that quietly picked
         * up somebody else's machine reads exactly like a correct one.
         */
        $assets = Asset::visibleTo($call->user)->whereIn('id', $asset_ids)->get();
        $strays = $assets->where('customer_id', '!=', $customer->id);

        if ($assets->count() !== count($asset_ids)) {
            $missing = array_diff($asset_ids, $assets->pluck('id')->all());

            return ToolResult::notFound('Machine(s) ' . implode(', ', $missing));
        }

        if ($strays->isNotEmpty()) {
            return ToolResult::failed(
                'Deze machines staan niet bij ' . $customer->name . ': '
                . $strays->pluck('serial_number')->implode(', ') . '.'
            );
        }

        $tickets = Ticket::visibleTo($call->user)->whereIn('id', $ticket_ids)->with('asset')->get();

        if ($tickets->count() !== count($ticket_ids)) {
            $missing = array_diff($ticket_ids, $tickets->pluck('id')->all());

            return ToolResult::notFound('Storing(en) ' . implode(', ', $missing));
        }

        $taken = $tickets->whereNotNull('service_order_id');

        if ($taken->isNotEmpty()) {
            return ToolResult::failed(
                'Deze storingen hangen al aan een werkbon: '
                . $taken->map(fn (Ticket $ticket) => '#' . $ticket->id . ' (werkbon #' . $ticket->service_order_id . ')')->implode(', ')
                . '.'
            );
        }

        $elsewhere = $tickets->filter(fn (Ticket $ticket) => $ticket->asset && $ticket->asset->customer_id !== $customer->id);

        if ($elsewhere->isNotEmpty()) {
            return ToolResult::failed(
                'Deze storingen horen bij een andere klant: ' . $elsewhere->pluck('id')->map(fn ($id) => '#' . $id)->implode(', ') . '.'
            );
        }

        $location_id = $call->integerArgument('location_id');

        if ($location_id !== null && !Location::where('id', $location_id)->where('customer_id', $customer->id)->exists()) {
            return ToolResult::failed('Die locatie hoort niet bij ' . $customer->name . '.');
        }

        $order = app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
            customer_id: $customer->id,
            project_id: $call->integerArgument('project_id'),
            location_id: $location_id,
            description: $call->stringArgument('description'),
            asset_ids: $assets->pluck('id')->all(),
            ticket_ids: $tickets->pluck('id')->all(),
        ));

        return ToolResult::ok(
            [
                'service_order_id' => $order->id,
                'customer' => $customer->name,
                'description' => $order->description,
                'assets' => $assets->pluck('serial_number')->all(),
                'tickets' => $tickets->pluck('id')->all(),
                'link' => '/serviceorders/' . $order->id,
            ],
            'Werkbon #' . $order->id . ' aangemaakt voor ' . $customer->name . '.',
        );
    }
}
