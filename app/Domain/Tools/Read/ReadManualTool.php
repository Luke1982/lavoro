<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\ApplicationManual;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\User;

/**
 * Reads the manual of Lavoro itself, for questions about the application rather
 * than about anybody's data.
 *
 * "Hoe sluit ik een werkbon af" is a different kind of question from "welke
 * werkbonnen staan er open": the first is answered from the manual, the second
 * from the database. Without this tool the assistant answered the first kind
 * from its general idea of what a field-service system probably does, which is
 * confidently wrong in exactly the places this application chose to be
 * different.
 *
 * Not to be confused with read_documentation, which reads the datasheets and
 * handleidingen filed against a product or machine.
 */
class ReadManualTool implements Tool
{
    /**
     * How many chapters travel back for one search. A term like "werkbon"
     * matches half the manual, and sending half the manual buries the question
     * it was fetched to answer.
     */
    private const CHAPTERS_PER_SEARCH = 3;

    public static function name(): string
    {
        return 'read_manual';
    }

    public function description(): string
    {
        return 'Leest de handleiding van Lavoro zelf: hoe de applicatie werkt, wat schermen en '
            . 'begrippen betekenen en hoe je iets voor elkaar krijgt. Gebruik dit bij vragen als '
            . '"hoe maak ik een werkbon aan", "wat betekent deze fase" of "waar stel ik rechten in" '
            . '— in plaats van uit je hoofd te antwoorden. Zonder argumenten krijg je de '
            . 'inhoudsopgave; met search of chapter de tekst zelf. Niet voor documentatie van '
            . 'producten of machines: daarvoor is read_documentation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Trefwoord om de handleiding op te doorzoeken, bijvoorbeeld '
                        . '"werkbon afsluiten" of "rechten". Je krijgt de best passende hoofdstukken.',
                ],
                'chapter' => [
                    'type' => 'string',
                    'description' => 'De slug van één hoofdstuk uit de inhoudsopgave, '
                        . 'als je precies weet welk hoofdstuk je wilt.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * The manual describes the application, never the data in it, so there is
     * nothing here one user may read and another may not.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Een vraag over de applicatie herkennen en er een trefwoord uit halen, meer is het niet. */
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
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $manual = app(ApplicationManual::class);

        if (!$manual->isAvailable()) {
            return ToolResult::failed(
                'De handleiding is niet aanwezig op deze installatie. Zeg dat je de vraag over '
                . 'de applicatie nu niet uit de handleiding kunt beantwoorden.'
            );
        }

        if ($slug = $call->stringArgument('chapter')) {
            return $this->oneChapter($manual, $slug);
        }

        if ($term = $call->stringArgument('search')) {
            return $this->bySearch($manual, $term);
        }

        return ToolResult::ok(
            [
                'chapters' => $manual->tableOfContents()->all(),
                'note' => 'Dit is de inhoudsopgave van de handleiding. Vraag de tekst van een '
                    . 'hoofdstuk op via chapter, of zoek met search.',
            ],
            'Inhoudsopgave van de handleiding opgehaald.',
        );
    }

    private function oneChapter(ApplicationManual $manual, string $slug): ToolResult
    {
        $chapter = $manual->chapter($slug);

        if ($chapter === null) {
            return ToolResult::ok(
                [
                    'chapters' => $manual->tableOfContents()->all(),
                    'note' => 'Hoofdstuk "' . $slug . '" bestaat niet. Kies een slug uit deze '
                        . 'inhoudsopgave.',
                ],
                'Hoofdstuk niet gevonden; inhoudsopgave teruggegeven.',
            );
        }

        return ToolResult::ok(
            [
                'chapter' => $chapter,
                'note' => $this->howToAnswer(),
            ],
            'Hoofdstuk "' . $chapter['title'] . '" uit de handleiding opgehaald.',
        );
    }

    private function bySearch(ApplicationManual $manual, string $term): ToolResult
    {
        $matches = $manual->matching($term);

        if ($matches->isEmpty()) {
            return ToolResult::ok(
                [
                    'chapters' => $manual->tableOfContents()->all(),
                    'note' => 'Niets gevonden voor "' . $term . '". Dit is de inhoudsopgave; '
                        . 'staat het onderwerp er niet tussen, zeg dan dat de handleiding er '
                        . 'niets over zegt in plaats van te gokken.',
                ],
                'Niets gevonden in de handleiding voor "' . $term . '".',
            );
        }

        $shown = $matches->take(self::CHAPTERS_PER_SEARCH);
        $left_out = $matches->slice(self::CHAPTERS_PER_SEARCH)->map(fn (array $chapter) => [
            'slug' => $chapter['slug'],
            'title' => $chapter['title'],
        ]);

        $content = [
            'chapters' => $shown->all(),
            'note' => $this->howToAnswer(),
        ];

        /**
         * What matched but did not travel is named rather than dropped, so the
         * model knows the answer may sit one chapter further and can ask for it
         * — instead of concluding the manual has nothing more to say.
         */
        if ($left_out->isNotEmpty()) {
            $content['also_matched'] = $left_out->values()->all();
        }

        return ToolResult::ok(
            $content,
            $shown->count() . ' hoofdstuk(ken) uit de handleiding opgehaald.',
        );
    }

    private function howToAnswer(): string
    {
        return 'Beantwoord de vraag op basis van deze tekst en zeg erbij dat het uit de '
            . 'handleiding komt. Staat het antwoord er niet in, zeg dat dan eerlijk.';
    }
}
