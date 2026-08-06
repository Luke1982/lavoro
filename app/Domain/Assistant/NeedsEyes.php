<?php

namespace App\Domain\Assistant;

/**
 * Whether a question is going to end up looking at pictures.
 *
 * Asked before the first round because it cannot be asked later: an assistant
 * turn carries the supplier's own raw content, so a conversation cannot change
 * providers halfway — handing DeepSeek's turns to Anthropic gets
 * "messages.1.content: Input should be a valid array".
 *
 * A guess, and a cheap one to get wrong in the right direction: guessing yes
 * costs a dearer model, guessing no costs a tool that has to refuse and a
 * person who has to ask again.
 */
class NeedsEyes
{
    /** What somebody says when they are about to point at something. */
    private const WORDS = ['foto', 'afbeelding', 'plaatje', 'typeplaat', 'serienummer', 'kijk naar', 'zie je'];

    public static function inQuestion(?string $question): bool
    {
        $asked = mb_strtolower((string) $question);

        foreach (self::WORDS as $word) {
            if (str_contains($asked, $word)) {
                return true;
            }
        }

        return false;
    }
}
