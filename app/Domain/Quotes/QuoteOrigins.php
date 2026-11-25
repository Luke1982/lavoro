<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteLineKinds;
use App\Models\Event;
use App\Models\FreeformMaterial;
use App\Models\Material;
use App\Models\Quote;
use App\Models\QuoteTemplate;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Quantity;
use App\Support\RichText;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a quote can be started from besides an empty page: a werkbon or a
 * storing, where a technician found work that needs an offer first. The quote
 * gets the customer, the address and the project, and the lines picked from
 * what the werkbon holds: each storing on it described, the materials used, and
 * the hours worked at the hourly rate from the settings.
 */
final class QuoteOrigins
{
    /** @var array<string, class-string<Model>> the kinds, as a request names them */
    public const KINDS = ['serviceorder' => ServiceOrder::class, 'ticket' => Ticket::class];

    public static function find(string $kind, int $id): ServiceOrder|Ticket
    {
        return self::KINDS[$kind]::findOrFail($id);
    }

    /** Whether this person may open it: a werkbon by its policy, a storing as its own page does. */
    public static function mayOpen(User $user, ServiceOrder|Ticket $origin): bool
    {
        return $origin instanceof Ticket ? $user->can('viewPage', Ticket::class) : $user->can('view', $origin);
    }

    /** How the trail names what a quote was made from: "werkbon WB-0272", "sjabloon 'Airco 1 ruimte'". */
    public static function label(Model $origin): string
    {
        return match (true) {
            $origin instanceof ServiceOrder => 'werkbon ' . $origin->number,
            $origin instanceof Ticket => 'storing #' . $origin->id . ' (' . $origin->subject . ')',
            $origin instanceof Quote => 'offerte ' . $origin->label(),
            $origin instanceof QuoteTemplate => "sjabloon '" . $origin->name . "'",
        };
    }

    /**
     * Who the quote is for and what it is called, to start the form with. An
     * address or project of another customer, which a werkbon can carry, is
     * left out: a quote only takes its own customer's.
     *
     * @return array{title: string, customer_id: ?int, location_id: ?int, project_id: ?int}
     */
    public static function heading(ServiceOrder|Ticket $origin): array
    {
        $own = fn (?Model $record) => $record?->customer_id === $origin->customer_id ? $record->getKey() : null;

        return $origin instanceof ServiceOrder
            ? [
                'title' => 'Naar aanleiding van werkbon ' . $origin->number,
                'customer_id' => $origin->customer_id,
                'location_id' => $own($origin->linkedLocation),
                'project_id' => $own($origin->project),
            ]
            : [
                'title' => $origin->subject,
                'customer_id' => $origin->asset?->resolvedCustomerId() ?? $origin->serviceOrder?->customer_id,
                'location_id' => $origin->asset?->resolvedLocationId(),
                'project_id' => null,
            ];
    }

    /**
     * Every line it offers to start from, as the form lists them to tick.
     *
     * @return array<int, array{key: string, label: string, detail: ?string}>
     */
    public static function choices(ServiceOrder|Ticket $origin): array
    {
        return array_values(array_map(fn (array $option) => [
            'key' => $option['key'],
            'label' => $option['label'],
            'detail' => $option['detail'],
        ], self::options($origin)));
    }

    /**
     * The picked lines, as QuoteContent::addLines takes them.
     *
     * @param  array<int, string>  $keys
     * @return array{lines: array<int, array<string, mixed>>}
     */
    public static function content(ServiceOrder|Ticket $origin, array $keys, Quote $quote): array
    {
        return ['lines' => array_values(array_map(
            fn (array $option) => ($option['line'])($quote),
            array_filter(self::options($origin), fn (array $option) => in_array($option['key'], $keys, true)),
        ))];
    }

    /**
     * Each line on offer: its key, how the form names it, and how it becomes a
     * quote line once the quote exists.
     *
     * @return array<int, array{key: string, label: string, detail: ?string, line: Closure}>
     */
    private static function options(ServiceOrder|Ticket $origin): array
    {
        if ($origin instanceof Ticket) {
            return [self::ticket($origin)];
        }

        $origin->load([
            'tickets', 'materials.usageUnit', 'freeformMaterials', 'taskInstances.materials.usageUnit',
            'taskInstances.freeformMaterials', 'events.executingUsers', 'events.executions',
        ]);

        $hours = $origin->events->sum(fn (Event $event) => $event->executingUsers->sum(
            fn (User $user) => $event->executions->firstWhere('user_id', $user->id)
                ?->hoursWorked((int) $user->pivot->breaktime) ?? 0
        ));

        return [
            ...$origin->tickets->map(fn (Ticket $ticket) => self::ticket($ticket))->all(),
            ...$origin->allMaterials()->map(fn (Material $material) => [
                'key' => 'material:' . $material->pivot->id,
                'label' => $material->name,
                'detail' => Quantity::human($material->pivot->quantity ?? 1) . ' ' . $material->usageUnit?->name,
                'line' => fn (Quote $quote) => QuoteLineDrafts::forMaterial(
                    $quote,
                    $material,
                    (float) ($material->pivot->quantity ?? 1),
                ),
            ])->all(),
            ...$origin->allFreeformMaterials()->map(fn (FreeformMaterial $material) => [
                'key' => 'freeform:' . $material->id,
                'label' => $material->description,
                'detail' => Quantity::human($material->quantity),
                'line' => fn (Quote $quote) => [
                    'kind' => QuoteLineKinds::free->value,
                    'description' => $material->description,
                    'quantity' => (float) $material->quantity,
                    'unit' => 'stuk',
                    'unit_price' => 0,
                    'vat_percent' => $quote->default_vat_percent,
                ],
            ])->all(),
            ...($hours > 0 ? [[
                'key' => 'hours',
                'label' => 'Gewerkte uren',
                'detail' => Quantity::human($hours) . ' uur',
                'line' => fn (Quote $quote) => [
                    'kind' => QuoteLineKinds::free->value,
                    'description' => 'Arbeid',
                    'quantity' => round($hours, 2),
                    'unit' => 'uur',
                    'unit_price' => QuoteSettings::hourlyRate() ?? 0,
                    'vat_percent' => $quote->default_vat_percent,
                ],
            ]] : []),
        ];
    }

    /** @return array{key: string, label: string, detail: ?string, line: Closure(Quote): array<string, mixed>} */
    private static function ticket(Ticket $ticket): array
    {
        return [
            'key' => 'ticket:' . $ticket->id,
            'label' => 'Storing: ' . $ticket->subject,
            'detail' => 'als tekst',
            'line' => fn () => [
                'kind' => QuoteLineKinds::text->value,
                'description' => $ticket->subject,
                'details' => $ticket->description
                    ? RichText::clean('<p>' . nl2br(e($ticket->description)) . '</p>')
                    : null,
                'quantity' => 0,
            ],
        ];
    }
}
