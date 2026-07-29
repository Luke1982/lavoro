<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\ServiceOrder;
use App\Models\User;

/**
 * Reads werkbonnen through the same visibility scope the index page uses, so a
 * monteur asking the assistant sees exactly the orders they would see by
 * clicking through the interface, and never one more.
 */
class SearchServiceOrderTool implements Tool
{
    public static function name(): string
    {
        return 'search_service_orders';
    }

    public function description(): string
    {
        return 'Zoekt werkbonnen op omschrijving, klant, inkoopordernummer of factuurnummer, '
            . 'eventueel beperkt tot één klant of alleen open werkbonnen. '
            . 'Gebruik dit voor vragen als "welke werkbonnen staan nog open bij klant X".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Vrije zoektekst in omschrijving, klantnaam of externe nummers.',
                ],
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Haal deze werkbonnen op via hun nummer.',
                ],
                'customer_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Beperk tot deze klanten. Geef ze in één keer mee, '
                        . 'niet één zoekopdracht per klant.',
                ],
                'only_open' => [
                    'type' => 'boolean',
                    'description' => 'Alleen werkbonnen die nog niet afgesloten zijn.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('list', ServiceOrder::class);
    }

    /** Vier filters, waarvan hooguit twee tegelijk uit een zin komen. */
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
        $limit = (int) config('assistant.max_results', 25);
        $search = $call->stringArgument('query');
        $customer_ids = $call->integerListArgument('customer_ids');
        $ids = $call->integerListArgument('ids');

        $query = ServiceOrder::query()
            ->visibleTo($call->user)
            ->with(['customer:id,name', 'serviceOrderStage:id,name,is_closed_state']);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($customer_ids !== []) {
            $query->whereIn('customer_id', $customer_ids);
        }

        if (filled($search)) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q
                ->where('description', 'like', $like)
                ->orWhere('external_purchaseorder_no', 'like', $like)
                ->orWhere('external_invoice_no', 'like', $like)
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $like)));
        }

        /**
         * Open is the absence of a closed stage, not the presence of an open one.
         * An order that never got a stage is open by ServiceOrder::is_closed, and
         * asking for a stage that says so would silently drop every one of them.
         */
        if ($call->argument('only_open') === true) {
            $query->whereDoesntHave('serviceOrderStage', fn ($sq) => $sq->where('is_closed_state', true));
        }

        $orders = $query->orderByDesc('id')->limit($limit)->get();

        $rows = $orders->map(fn (ServiceOrder $order) => [
            'id' => $order->id,
            'description' => $order->description,
            'customer' => $order->customer?->name,
            'customer_id' => $order->customer_id,
            'stage' => $order->serviceOrderStage?->name,
            'is_closed' => $order->is_closed,
            'closed_on' => $order->closed_on,
        ])->all();

        return ToolResult::ok(
            ['service_orders' => $rows],
            count($rows) . ' werkbon(nen) gevonden.',
        );
    }
}
