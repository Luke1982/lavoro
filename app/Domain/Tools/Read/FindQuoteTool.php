<?php

namespace App\Domain\Tools\Read;

use App\Domain\Planning\Clock;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Enums\QuoteStatuses;
use App\Models\Quote;
use App\Models\User;

/**
 * Reads offertes the way the offertelijst shows them: number, title, customer,
 * status and the total excluding VAT.
 *
 * The total is the sale amount the list shows to everyone who may read quotes;
 * purchase prices and margins stay behind their own right and are not read here.
 */
class FindQuoteTool implements Tool
{
    use ReportsTheWholeCount;

    public static function name(): string
    {
        return 'find_quotes';
    }

    public function description(): string
    {
        return 'Zoekt offertes op nummer, titel, klant, status of offertedatum, met het totaal excl. btw. '
            . 'Gebruik dit voor "welke offertes staan nog open" of "de offerte voor klant X".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Offertenummer (OF-2026-014), een woord uit de titel of de klantnaam.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Beperk tot deze klant.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [Quote::LIST_OPEN, ...array_column(QuoteStatuses::cases(), 'value')],
                    'description' => 'open: alles waar nog geen antwoord op is.',
                ],
                'quote_date_before' => [
                    'type' => 'string',
                    'description' => 'Offertedatum voor deze dag, JJJJ-MM-DD.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('viewAny', Quote::class);
    }

    /** Vier filters die letterlijk in de vraag staan. */
    public static function difficulty(): int
    {
        return 2;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $limit = (int) config('assistant.max_results', 25);

        $query = Quote::query()
            ->with(['customer:id,name', 'lines'])
            ->searchedFor($call->stringArgument('query') ?? '');

        if ($customer_id = $call->integerArgument('customer_id')) {
            $query->where('customer_id', $customer_id);
        }

        if (filled($wanted = $call->stringArgument('status'))) {
            $status = $this->listStatusOf($wanted);

            if ($status === null) {
                return ToolResult::failed('Onbekende status "' . $wanted . '". Kies ' . Quote::LIST_OPEN . ' of '
                    . implode(', ', array_column(QuoteStatuses::cases(), 'value')) . '.');
            }

            $query->inListStatus($status);
        }

        if ($complaint = $call->invalidDateAmong('quote_date_before')) {
            return ToolResult::failed($complaint);
        }

        $before = $call->dateArgument('quote_date_before');

        $query->when($before, fn ($q) => $q->whereDate('quote_date', '<', $before->toDateString()));

        $matching = clone $query;
        $quotes = $query->orderByDesc('quote_date')->orderByDesc('id')->limit($limit)->get();

        $rows = $quotes->map(fn (Quote $quote) => [
            'quote_id' => $quote->id,
            'number' => $quote->number,
            'title' => $quote->title,
            'status' => $quote->status,
            'customer' => $quote->customer?->name,
            'customer_id' => $quote->customer_id,
            'quote_date' => $quote->quote_date?->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'sent_on' => $quote->sent_at ? Clock::toLocal($quote->sent_at)->toDateString() : null,
            'total_excl_vat' => $quote->totals()->net_cents / 100,
            'link' => '/quotes/' . $quote->id,
        ])->all();

        return $this->answerWithCount(['quotes' => $rows], count($rows), $matching, $limit, 'offertes');
    }

    /** The status as the list's filter names it, however the model capitalised it; null when there is none. */
    private function listStatusOf(string $wanted): ?string
    {
        $wanted = mb_strtolower($wanted);

        if ($wanted === Quote::LIST_OPEN) {
            return Quote::LIST_OPEN;
        }

        foreach (QuoteStatuses::cases() as $status) {
            if (mb_strtolower($status->value) === $wanted) {
                return $status->value;
            }
        }

        return null;
    }
}
