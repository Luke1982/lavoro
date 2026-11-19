<?php

namespace App\Domain\Signals;

/**
 * "Naam gewijzigd van 'a' naar 'b', Zoekbaar gewijzigd van 'Nee' naar 'Ja'":
 * the sentence of a list of change rows. One wording, so an entry written at
 * once and one folded together from several saves read alike.
 */
final class ChangeSentence
{
    /** @param  iterable<array{label: ?string, old_label: ?string, new_label: ?string}>  $changes */
    public static function of(iterable $changes): string
    {
        $parts = [];

        foreach ($changes as $change) {
            $parts[] = $change['label'] . ' gewijzigd van \'' . ($change['old_label'] ?? '-')
                . '\' naar \'' . ($change['new_label'] ?? '-') . '\'';
        }

        return implode(', ', $parts);
    }
}
