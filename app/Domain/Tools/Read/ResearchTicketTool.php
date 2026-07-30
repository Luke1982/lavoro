<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\ProductDocumentation;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Remark;
use App\Models\Ticket;
use App\Models\User;

/**
 * Everything known about one fault, gathered for thinking about rather than
 * reporting.
 *
 * Asked how to solve something, the pieces that help are never in one place: what
 * the caller said, what was written on it since, what has gone wrong on the same
 * model before, what those people did about it, and what the manufacturer says.
 * Chaining four tools to collect that spends four round trips and usually ends
 * with the assistant working from whichever part it fetched last.
 *
 * The history of the same product is the valuable part and the part nobody has
 * time to look up: a fault seen four times on one model, closed the same way each
 * time, is an answer.
 */
class ResearchTicketTool implements Tool
{
    private const SIMILAR_TICKETS = 8;

    public static function name(): string
    {
        return 'research_ticket';
    }

    public function description(): string
    {
        return 'Haalt alles op wat over een storing bekend is: de melding zelf, de opmerkingen erbij, '
            . 'eerdere storingen op hetzelfde producttype met hun opmerkingen en wie ze afsloot, en de '
            . 'technische documentatie van het apparaat. Gebruik dit als iemand om advies of hulp vraagt '
            . 'bij het oplossen van een storing, in plaats van los te zoeken.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticket_id' => [
                    'type' => 'integer',
                    'description' => 'De storing waar het om gaat.',
                ],
            ],
            'required' => ['ticket_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** De melding, de geschiedenis van hetzelfde producttype en de documentatie tegen elkaar afwegen. */
    public static function difficulty(): int
    {
        return 8;
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
        $ticket_id = $call->integerArgument('ticket_id');

        $ticket = Ticket::visibleTo($call->user)
            ->with(['asset.product.brand', 'asset.product.productType', 'asset.customer:id,name', 'closedBy:id,name'])
            ->whereKey($ticket_id)
            ->first();

        if ($ticket === null) {
            return ToolResult::notFound('Storing #' . ($ticket_id ?? '?'));
        }

        $product = $ticket->asset?->product;

        /**
         * Scoped like everything else, so "what happened before on this model" can
         * never become a way to read storingen somebody could not otherwise open.
         */
        $similar = $product === null ? collect() : Ticket::visibleTo($call->user)
            ->whereKeyNot($ticket->id)
            ->whereHas('asset', fn ($q) => $q->where('product_id', $product->id))
            ->with(['closedBy:id,name', 'asset:id,serial_number'])
            ->orderByDesc('closed_on')
            ->orderByDesc('id')
            ->limit(self::SIMILAR_TICKETS)
            ->get();

        $content = [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'status_code' => $ticket->status_code,
                'machine' => $product?->display_name,
                'serial_number' => $ticket->asset?->serial_number,
                'customer' => $ticket->asset?->customer?->name,
                'remarks' => $this->remarksOn($ticket),
            ],
            'earlier_on_this_product' => $similar->map(fn (Ticket $other) => [
                'id' => $other->id,
                'subject' => $other->subject,
                'description' => $other->description,
                'status' => $other->status,
                'serial_number' => $other->asset?->serial_number,
                'closed_on' => $other->closed_on,
                'closed_by' => $other->closedBy?->name,
                'remarks' => $this->remarksOn($other),
            ])->all(),
        ];

        if ($product === null) {
            $content['note'] = 'Deze storing hangt aan geen machine met een product, dus er is geen '
                . 'documentatie en geen geschiedenis van hetzelfde type om mee te vergelijken.';

            return ToolResult::ok($content, 'Storing opgehaald, zonder producthistorie.');
        }

        $files = app(ProductDocumentation::class)->forAsset($ticket->asset, $call->user);

        if ($files->isEmpty()) {
            $content['note'] = 'Er is geen technische documentatie opgeslagen bij dit product. Baseer je '
                . 'advies dus op de melding en op wat er eerder op dit type is gebeurd, en zeg erbij dat '
                . 'er geen documentatie beschikbaar was.';

            return ToolResult::ok($content, 'Storing en historie opgehaald, geen documentatie.');
        }

        if (!app(TalksToModel::class)->readsDocuments()) {
            $content['documents'] = $files->map(fn ($file) => $file->name)->all();
            $content['note'] = 'Er is documentatie, maar deze AI-aanbieder kan documenten niet lezen. '
                . 'Noem welke er zijn en geef advies op basis van de melding en de historie.';

            return ToolResult::ok($content, 'Documentatie aanwezig maar niet leesbaar.');
        }

        $content['documents'] = $files->map(fn ($file) => $file->name)->all();
        $content['note'] = 'De documentatie is bij dit gesprek gevoegd. Denk mee over de oorzaak en de '
            . 'volgorde van controleren, verwijs naar wat er eerder op dit type gebeurde en naar de '
            . 'documentatie, en verzin geen stappen die er niet in staan. Zeg het als je het niet weet.';

        return ToolResult::ok(
            $content,
            'Storing, ' . $similar->count() . ' eerdere melding(en) en ' . $files->count() . ' document(en) opgehaald.',
        )->showing($files->all());
    }

    /**
     * Both kinds. What somebody wrote for internal eyes is usually where the
     * actual diagnosis ended up.
     *
     * @return array<int, array{internal: bool, by: ?string, on: ?string, text: string}>
     */
    private function remarksOn(Ticket $ticket): array
    {
        return $ticket->remarks()->with('user:id,name')->get()
            ->concat($ticket->internalRemarks()->with('user:id,name')->get())
            ->map(fn (Remark $remark) => [
                'internal' => (bool) $remark->pivot->internal,
                'by' => $remark->user?->name,
                'on' => $remark->created_at?->toDateString(),
                'text' => $remark->content,
            ])
            ->all();
    }
}
