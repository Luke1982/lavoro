<?php

namespace App\Domain\Tools\Read\Concerns;

use Illuminate\Support\Collection;

/**
 * Turns a handful of matches into a choice the box can put buttons on.
 *
 * There is a tool for asking which one, and the model uses it about half the time
 * — the same question twice got buttons once and a bulleted list the next. Leaving
 * that to its judgement was the mistake: when a lookup comes back with a few
 * records and no way to tell them apart, the ambiguity is in the data, so the data
 * can say so.
 *
 * Only for a few. Two to eight is somebody meaning one of these; twenty-five is a
 * list they asked for, and buttons on all of them would be noise.
 */
trait OffersAChoice
{
    private const FEWEST = 2;

    private const MOST = 8;

    /**
     * @param  Collection<int, mixed>  $records
     * @param  callable(mixed): string  $label
     * @return array{question: string, options: array<int, array{label: string, reference: string, link: ?string}>}|null
     */
    private function choiceOf(Collection $records, string $question, string $noun, string $path, callable $label): ?array
    {
        if ($records->count() < self::FEWEST || $records->count() > self::MOST) {
            return null;
        }

        return [
            'question' => $question,
            'options' => $records->map(fn ($record) => [
                'label' => mb_substr($label($record), 0, 120),
                'reference' => $noun . ' #' . $record->id,
                'link' => '/' . $path . '/' . $record->id,
            ])->values()->all(),
        ];
    }
}
