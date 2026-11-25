<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Reading\RecordKinds;

/**
 * Turns the page somebody is looking at into a sentence the model can use.
 *
 * Without this "wie heeft deze order gesloten?" cannot be answered at all: the
 * word "deze" is doing all the work and the assistant has no idea what is on
 * screen, so it can only ask which one — while the number is in the address bar.
 *
 * The path arrives from the browser and is matched against a fixed list rather
 * than read. Anything unrecognised produces nothing, so a made-up path cannot
 * put words of its own into the prompt.
 *
 * The pages and their nouns come from RecordKinds, the same words the history
 * search and the read tool accept, so a page the assistant is told about is a
 * page it can look up without translating.
 */
class PageContext
{
    public function describe(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', parse_url($path, PHP_URL_PATH) ?: '')));

        if ($segments === []) {
            return '';
        }

        $resource = $segments[0];
        $id = $segments[1] ?? null;

        /**
         * Round-tripping the number is what rules out one too long to hold: cast
         * on its own saturates at the largest integer there is, and "werkbon
         * #9223372036854775807" then goes into the prompt as though it were real.
         */
        $is_id = $id !== null && ctype_digit($id) && (string) (int) $id === $id;

        $pages = RecordKinds::recordPages();

        if ($is_id && isset($pages[$resource])) {
            return 'De gebruiker kijkt naar ' . RecordKinds::noun($pages[$resource]) . ' #' . (int) $id . '. '
                . 'Als de vraag "deze" of "dit" bevat zonder verder iets te noemen, gaat het daarover.';
        }

        if ($id === null && ($screen = RecordKinds::screenName($resource)) !== null) {
            return 'De gebruiker kijkt naar ' . $screen . '.';
        }

        return '';
    }
}
