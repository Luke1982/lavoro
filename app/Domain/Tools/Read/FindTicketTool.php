<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Ticket;
use App\Models\User;

/**
 * Reads storingen: what is wrong, on which machine, and who dealt with it.
 *
 * The machine is the point. A storing's history records that a priority moved
 * from Laag to Hoog and little else, so working from the timeline alone leaves
 * the assistant unable to say which machine a storing is even about — while the
 * link sits in plain view at the top of the page.
 */
class FindTicketTool implements Tool
{
    public static function name(): string
    {
        return 'find_tickets';
    }

    public function description(): string
    {
        return 'Zoekt storingen met de machine, de klant en de werkbon erbij, en wie de storing afsloot. '
            . 'Gebruik dit bij elke vraag over een storing: welke machine het betreft, wat er speelt, '
            . 'of wie eerder een vergelijkbare storing heeft opgelost.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Haal deze storingen op via hun nummer.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Vrije zoektekst in onderwerp of omschrijving.',
                ],
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot storingen op deze machine.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot storingen bij deze klant.',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot storingen op machines van dit product. '
                        . 'Handig om te zien wie ervaring heeft met een bepaald type machine.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Everyone may ask; the scope on Ticket decides what comes back, which is the
     * same set they would get by opening the storingen they have access to.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Een storing opzoeken en de machine, klant en afsluiter eruit lezen; filteren op een paar velden. */
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

        $query = Ticket::query()
            ->visibleTo($call->user)
            ->with([
                'asset:id,serial_number,product_id,customer_id',
                'asset.product:id,brand_id,model',
                'asset.product.brand:id,name',
                'asset.customer:id,name',
                'closedBy:id,name',
                'serviceOrder:id,description',
            ]);

        if ($ids = $call->integerListArgument('ids')) {
            $query->whereIn('id', $ids);
        }

        if ($search = $call->stringArgument('query')) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q
                ->where('subject', 'like', $like)
                ->orWhere('description', 'like', $like));
        }

        if ($asset_id = $call->integerArgument('asset_id')) {
            $query->where('asset_id', $asset_id);
        }

        if ($customer_id = $call->integerArgument('customer_id')) {
            $query->whereHas('asset', fn ($q) => $q->where('customer_id', $customer_id));
        }

        if ($product_id = $call->integerArgument('product_id')) {
            $query->whereHas('asset', fn ($q) => $q->where('product_id', $product_id));
        }

        $tickets = $query->orderByDesc('id')->limit($limit)->get();

        $rows = $tickets->map(fn (Ticket $ticket) => [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'status_code' => $ticket->status_code,
            'asset_id' => $ticket->asset_id,
            'machine' => $ticket->asset?->product?->display_name,
            'serial_number' => $ticket->asset?->serial_number,
            'product_id' => $ticket->asset?->product_id,
            'customer' => $ticket->asset?->customer?->name,
            'customer_id' => $ticket->asset?->customer_id,
            'service_order_id' => $ticket->service_order_id,
            'closed_on' => $ticket->closed_on,
            'closed_by' => $ticket->closedBy?->name,
            'closed_by_id' => $ticket->closed_by_id,
        ])->all();

        return ToolResult::ok(
            ['tickets' => $rows],
            count($rows) . ' storing(en) gevonden.',
        );
    }
}
