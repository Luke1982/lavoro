<?php

namespace App\Domain\Assistant;

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
 * The nouns match the ones SearchActivityTool accepts, so a page the assistant
 * is told about is a page it can look the history up for without translating.
 */
class PageContext
{
    private const RECORDS = [
        'serviceorders' => 'werkbon',
        'tickets' => 'storing',
        'assets' => 'machine',
        'customers' => 'klant',
        'projects' => 'project',
        'events' => 'afspraak',
        'products' => 'product',
    ];

    private const SCREENS = [
        'planner' => 'de planning',
        'serviceorders' => 'het overzicht van werkbonnen',
        'tickets' => 'het overzicht van storingen',
        'assets' => 'het overzicht van machines',
        'customers' => 'het klantenoverzicht',
        'projects' => 'het projectenoverzicht',
        'products' => 'het productenoverzicht',
    ];

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

        if ($is_id && isset(self::RECORDS[$resource])) {
            return 'De gebruiker kijkt naar ' . self::RECORDS[$resource] . ' #' . (int) $id . '. '
                . 'Als de vraag "deze" of "dit" bevat zonder verder iets te noemen, gaat het daarover.';
        }

        if ($id === null && isset(self::SCREENS[$resource])) {
            return 'De gebruiker kijkt naar ' . self::SCREENS[$resource] . '.';
        }

        return '';
    }
}
