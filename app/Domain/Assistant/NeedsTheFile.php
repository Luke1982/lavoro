<?php

namespace App\Domain\Assistant;

/**
 * Whether a follow-up is still about the file that was sent earlier.
 *
 * The same trade as [[NeedsEyes]] and for the same reason: a parked file goes
 * back up with the question, and a datasheet of twenty pages is thousands of
 * tokens. Sending it on every follow-up would put the whole thing on the wire
 * again to answer "en wie is de klant van deze werkbon" — so it only rides
 * along when the question sounds like it is about the file.
 *
 * Wrong in the cheap direction costs a re-upload nobody needed; wrong in the
 * other costs an answer about a document the model can no longer see.
 */
class NeedsTheFile
{
    /** What somebody says when they mean the thing they just attached. */
    private const WORDS = [
        'document', 'bestand', 'bijlage', 'pdf', 'datasheet', 'datablad',
        'handleiding', 'specificatie', 'spec', 'brochure', 'offerte', 'rapport',
        'staat er', 'staat erin', 'lees',
    ];

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
