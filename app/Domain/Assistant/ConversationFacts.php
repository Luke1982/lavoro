<?php

namespace App\Domain\Assistant;

use App\Domain\Tools\ToolResult;
use App\Models\AssistantConversationFact;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a conversation has settled, kept so it does not have to be remembered.
 *
 * A model holds the thread in prose, and prose comes loose. Told that a customer
 * had one open werkbon, #4, it later looked up customer #4, found a different
 * company entirely, and spent the rest of the conversation planning an
 * installation for them. Every record here is a bare integer and nothing in a
 * transcript says which table one came from, so there was nothing to catch it.
 *
 * These are written from what the tools returned, never from what the answer
 * says about them — the same rule the rest of this applies. A lookup that comes
 * back with exactly one record is that record being established; one that comes
 * back with twenty is a list, and settles nothing.
 */
class ConversationFacts
{
    /**
     * Where a single record of each kind is found in a tool result, and what to
     * call it in Dutch. Keyed by the array a tool returns.
     *
     * @var array<string, array{key: string, noun: string}>
     */
    private const FROM_RESULTS = [
        'customers' => ['key' => 'klant', 'noun' => 'klant'],
        'service_orders' => ['key' => 'werkbon', 'noun' => 'werkbon'],
        'assets' => ['key' => 'machine', 'noun' => 'machine'],
        'tickets' => ['key' => 'storing', 'noun' => 'storing'],
        'products' => ['key' => 'product', 'noun' => 'product'],
        'appointments' => ['key' => 'afspraak', 'noun' => 'afspraak'],
    ];

    /**
     * The same thing said the other way round: a record named outright by its own
     * id, rather than arrived at through a list.
     *
     * This is how a write reports itself — creating a werkbon hands back
     * service_order_id and nothing that looks like a search result — so without it
     * the conversation remembered everything it looked up and nothing it made.
     *
     * @var array<string, string>
     */
    private const FROM_IDS = [
        'customer_id' => 'klant',
        'service_order_id' => 'werkbon',
        'asset_id' => 'machine',
        'ticket_id' => 'storing',
        'product_id' => 'product',
        'event_id' => 'afspraak',
        'location_id' => 'locatie',
    ];

    /** @return array<string, array{id: int, label: ?string}> */
    public function for(?string $conversation, User $user): array
    {
        if (blank($conversation)) {
            return [];
        }

        return AssistantConversationFact::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation)
            ->value('facts') ?? [];
    }

    /**
     * Takes from a result whatever it settled, and keeps it.
     *
     * Never the reason a question fails: this is a convenience for the next turn,
     * and a conversation that cannot write its notes down is still a conversation.
     */
    public function learn(?string $conversation, User $user, ToolResult $result): void
    {
        if (blank($conversation) || $result->is_error || !is_array($result->content)) {
            return;
        }

        $learned = $this->readFrom($result->content);

        if ($learned === []) {
            return;
        }

        try {
            $row = AssistantConversationFact::firstOrNew([
                'user_id' => $user->id,
                'conversation_id' => $conversation,
            ]);

            /** The newest wins: somebody correcting themselves is the usual case. */
            $row->facts = array_merge($row->facts ?? [], $learned);
            $row->save();
        } catch (Throwable $e) {
            Log::warning('Kon gespreksfeiten niet opslaan', ['exception' => $e]);
        }
    }

    /**
     * The line that goes to the model, or nothing when there is nothing settled.
     *
     * @param  array<string, array{id: int, label: ?string}>  $facts
     */
    public function sentence(array $facts): string
    {
        if ($facts === []) {
            return '';
        }

        $parts = [];

        foreach ($facts as $noun => $fact) {
            $parts[] = $noun . ' #' . $fact['id'] . (blank($fact['label']) ? '' : ' (' . $fact['label'] . ')');
        }

        /**
         * The warning is the point of the sentence. Numbers here are bare and every
         * table has a #4, so the failure this exists to stop is reading one kind of
         * number as another — which is exactly how a werkbon number became a
         * customer number and stayed one.
         */
        return 'In dit gesprek staat al vast: ' . implode(', ', $parts)
            . '. Gebruik die nummers en zoek ze niet opnieuw op. Ze horen elk bij hun eigen soort record: '
            . 'een werkbonnummer is geen klantnummer. Klopt er iets niet meer, ga dan af op wat de '
            . 'gebruiker nu zegt.';
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array{id: int, label: ?string}>
     */
    private function readFrom(array $content): array
    {
        $learned = [];

        foreach (self::FROM_IDS as $key => $noun) {
            $id = $content[$key] ?? null;

            /** Only a real number at the top of the result, never one buried in a row. */
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                continue;
            }

            if ((int) $id > 0) {
                $learned[$noun] = ['id' => (int) $id, 'label' => $this->labelIn($content)];
            }
        }

        foreach (self::FROM_RESULTS as $array => $meaning) {
            $rows = $content[$array] ?? null;

            /** One is an answer. More than one is a list, and a list settles nothing. */
            if (!is_array($rows) || count($rows) !== 1) {
                continue;
            }

            $row = reset($rows);

            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $learned[$meaning['key']] = [
                'id' => (int) $row['id'],
                'label' => $this->labelIn($row),
            ];
        }

        return $learned;
    }

    /** @param array<string, mixed> $row */
    private function labelIn(array $row): ?string
    {
        foreach (['name', 'customer', 'description', 'subject', 'what'] as $key) {
            if (filled($row[$key] ?? null) && is_string($row[$key])) {
                return mb_substr($row[$key], 0, 80);
            }
        }

        return null;
    }
}
