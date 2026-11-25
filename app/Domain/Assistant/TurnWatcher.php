<?php

namespace App\Domain\Assistant;

use App\Domain\Tools\ToolResult;
use Closure;

/**
 * Watches the tool calls of one turn, and picks out anything the box has to put
 * in front of somebody: an action waiting to be agreed to, or a choice waiting
 * to be made.
 */
final class TurnWatcher
{
    /** @var array<int, array{name: string, arguments: array, failed: bool}> */
    public array $tools = [];

    /** @var array<int, array{tool: string, preview: ?string, arguments: array, token: string}> */
    public array $pending = [];

    /** @var array<int, array{question: string, options: array}> */
    public array $choices = [];

    /** @var array<int, string> What every tool handed back, as the model read it. */
    public array $seen = [];

    /** @var array<int, array<string, mixed>> What was read off photos, per part, with how sure. */
    public array $findings = [];

    /** @var array<int, string> */
    public array $unreadable = [];

    /** @var array<int, string> Photo addresses by image id. */
    public array $photos = [];

    /** @var array<int, array<string, mixed>> */
    public array $tours = [];

    /** @var array<int, Closure> */
    private array $listeners = [];

    /** @var array<int, true> Positions in the choices list that a lookup filled, not the model. */
    private array $offered_by_lookups = [];

    public function __construct(private readonly Closure $learn) {}

    /** Somebody else wants to hear about every call too, as it happens. */
    public function listen(Closure $listener): self
    {
        $this->listeners[] = $listener;

        return $this;
    }

    public function __invoke(string $name, array $arguments, bool $failed, ?ToolResult $result = null): void
    {
        $this->tools[] = ['name' => $name, 'arguments' => $arguments, 'failed' => $failed];

        foreach ($this->listeners as $listener) {
            $listener($name, $arguments, $failed, $result);
        }

        $this->seen[] = $result?->toModelContent() ?? '';

        if ($result === null) {
            return;
        }

        ($this->learn)($result);
        $content = is_array($result->content) ? $result->content : [];
        $status = $content['status'] ?? null;

        if ($status === 'bevestiging_nodig') {
            $this->pending[] = [
                'tool' => $name,
                'preview' => $content['preview'] ?? null,
                'arguments' => $content['proposed'] ?? [],
                'token' => $content['confirmation_token'],
            ];
        }

        /**
         * Where the photos live, so a finding can be shown beside the picture
         * it was read off. The box never sees a tool result, so the addresses
         * have to travel with the answer.
         */
        foreach ($content['images'] ?? [] as $photo) {
            if (filled($photo['url'] ?? null)) {
                $this->photos[(int) $photo['image_id']] = $photo['url'];
            }
        }

        /**
         * Merged across calls rather than kept per call: grouped by call, six
         * photos read in two batches drew two boxes that described when the
         * model spoke, not what it was looking at.
         */
        if (isset($content['findings'])) {
            $this->findings = array_merge($this->findings, $content['findings']);
            $this->unreadable = array_merge($this->unreadable, $content['unreadable'] ?? []);
        }

        if (isset($content['tour_offer']) && !$failed) {
            $this->tours[] = $content['tour_offer'];
        }

        /**
         * A question the model asks itself outranks one a lookup offered before
         * it: that one was about something the model has since decided, and two
         * pickers side by side got two contradictory answers. A choice offered
         * after the question is about something new and stays.
         */
        if ($status === 'keuze_nodig') {
            $this->choices = array_values(array_diff_key($this->choices, $this->offered_by_lookups));
            $this->offered_by_lookups = [];
            $this->choices[] = ['question' => $content['question'], 'options' => $content['options']];
        }

        /**
         * A lookup can offer a choice of its own, without the model deciding to
         * ask. Taken from the result rather than from what it says about it.
         */
        if (isset($content['choice']['options'])) {
            $this->offered_by_lookups[count($this->choices)] = true;
            $this->choices[] = [
                'question' => $content['choice']['question'] ?? 'Welke bedoel je?',
                'options' => $content['choice']['options'],
            ];
        }
    }

    /** What the box draws under an answer, the same after a question as after a click. */
    public function toArray(): array
    {
        return [
            'tools' => $this->tools,
            'pending' => $this->pending,
            'choices' => $this->choices,
            'findings' => $this->findings,
            'unreadable' => array_values(array_unique($this->unreadable)),
            'photos' => $this->photos,
            'tours' => $this->tours,
        ];
    }
}
