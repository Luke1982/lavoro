<?php

namespace App\Domain\Tools\Write;

use App\Actions\Tickets\CreateTicketAction;
use App\Actions\Tickets\NewTicket;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ChecksEnums;
use App\Enums\TicketPriorities;
use App\Enums\TicketStatusses;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;

/**
 * Logs a storing against a machine.
 *
 * A storing always belongs to a machine — the column does not allow otherwise —
 * so the machine is the one thing that cannot be guessed at. Given a serial
 * number the assistant is expected to look it up first rather than invent an id.
 */
class CreateTicketTool implements Confirmable, Tool
{
    use ChecksEnums;

    public static function name(): string
    {
        return 'create_ticket';
    }

    public function description(): string
    {
        return 'Legt een storing vast op een machine. Zoek de machine eerst op als je alleen '
            . 'een serienummer of klantnaam hebt. Roep deze tool aan zodra je de machine, het onderwerp '
            . 'en de omschrijving hebt: er wordt nog niets vastgelegd, je krijgt terug dat er bevestiging '
            . 'nodig is en het systeem legt de knop aan de gebruiker voor. Vraag dus niet zelf eerst '
            . 'in tekst om toestemming; dan gebeurt er namelijk helemaal niets.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'De machine waar de storing op zit.',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Korte omschrijving, zoals die in het overzicht komt te staan.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Wat er aan de hand is, in de woorden van de melder.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['Laag', 'Normaal', 'Hoog'],
                    'description' => 'Standaard Normaal.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['Open', 'In behandeling', 'Gesloten'],
                    'description' => 'Standaard Open.',
                ],
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'De werkbon waar de storing meteen bij hoort. Laat weg voor een losse storing.',
                ],
            ],
            'required' => ['asset_id', 'subject', 'description'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Ticket::class);
    }

    /** Uit een melding het probleem, de machine en de urgentie halen. */
    public static function difficulty(): int
    {
        return 4;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function previewOf(ToolCall $call): string
    {
        $asset = Asset::find($call->integerArgument('asset_id'));

        return 'Storing vastleggen'
            . ($asset ? ' op ' . ($asset->serial_number ?? 'machine #' . $asset->id) : '')
            . (blank($call->stringArgument('subject')) ? '' : ' — ' . $call->stringArgument('subject'))
            . ' (prioriteit ' . ($call->stringArgument('priority') ?: 'Normaal') . ')';
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $subject = $call->stringArgument('subject');
        $description = $call->stringArgument('description');

        if (blank($subject) || blank($description)) {
            return ToolResult::failed('Een storing heeft een onderwerp en een omschrijving nodig.');
        }

        $asset_id = $call->integerArgument('asset_id');

        /**
         * Checked against what this person may see rather than against the table.
         * A storing logged on a machine they cannot open would be invisible to
         * them the moment it was made.
         */
        $asset = $asset_id === null ? null : Asset::visibleTo($call->user)->whereKey($asset_id)->first();

        if ($asset === null) {
            return ToolResult::notFound('Machine #' . ($asset_id ?? '?'));
        }

        $priority = $this->oneOf($call->stringArgument('priority'), TicketPriorities::class, TicketPriorities::normaal->value);
        $status = $this->oneOf($call->stringArgument('status'), TicketStatusses::class, TicketStatusses::open->value);

        if ($priority === null || $status === null) {
            return ToolResult::failed(
                'Onbekende prioriteit of status. Prioriteit: ' . $this->valuesOf(TicketPriorities::class)
                . '. Status: ' . $this->valuesOf(TicketStatusses::class) . '.'
            );
        }

        $service_order_id = $call->integerArgument('service_order_id');

        if ($service_order_id !== null && !$this->orderIsVisible($call, $service_order_id)) {
            return ToolResult::notFound('Werkbon #' . $service_order_id);
        }

        $ticket = app(CreateTicketAction::class)->execute(new NewTicket(
            asset_id: $asset->id,
            subject: $subject,
            description: $description,
            status: $status,
            priority: $priority,
            created_by_id: $call->user->id,
            service_order_id: $service_order_id,
        ));

        return ToolResult::ok(
            [
                'ticket_id' => $ticket->id,
                'subject' => $ticket->subject,
                'machine' => $asset->product?->display_name,
                'serial_number' => $asset->serial_number,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'service_order_id' => $ticket->service_order_id,
                'link' => '/tickets/' . $ticket->id,
            ],
            'Storing #' . $ticket->id . ' vastgelegd op ' . ($asset->serial_number ?? 'machine #' . $asset->id) . '.',
        );
    }

    private function orderIsVisible(ToolCall $call, int $service_order_id): bool
    {
        return ServiceOrder::visibleTo($call->user)->whereKey($service_order_id)->exists();
    }
}
