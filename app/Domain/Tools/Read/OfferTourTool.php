<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tours\TourCatalog;
use App\Models\TourProgress;
use App\Models\User;

/**
 * Puts a button under the answer that starts a rondleiding on the screen: the
 * whole of one, or a single chapter.
 *
 * "Hoe kopieer ik een afspraak" is answered better by being shown than by being
 * told, and the rondleiding already knows how to show it. This tool only picks
 * which part; the button travels with the answer, the way a choice does, and
 * the screen starts it with the same filtering on rights it always uses.
 */
class OfferTourTool implements Tool
{
    public static function name(): string
    {
        return 'offer_tour';
    }

    public function description(): string
    {
        return 'Zet onder je antwoord een knop die een rondleiding op het scherm start: geanimeerde uitleg met '
            . 'gesproken tekst. Gebruik dit bij hoe-vragen over een scherm met een rondleiding, of als iemand om '
            . 'uitleg of een rondleiding vraagt. Met chapter één hoofdstuk, zonder de hele rondleiding.';
    }

    public function inputSchema(): array
    {
        $catalog = app(TourCatalog::class);
        $chapters = collect($catalog->offeredIds())->flatMap(fn (string $tour) => $catalog->chapterIds($tour))->unique()->values()->all();

        return [
            'type' => 'object',
            'properties' => [
                'tour' => [
                    'type' => 'string',
                    'enum' => $catalog->offeredIds(),
                    'description' => 'activiteiten: aankomende keuringen.',
                ],
                'chapter' => [
                    'type' => 'string',
                    'enum' => $chapters,
                    'description' => 'Eén hoofdstuk als de vraag over één ding gaat; weglaten voor alles.',
                ],
            ],
            'required' => ['tour'],
            'additionalProperties' => false,
        ];
    }

    /** Which chapters somebody gets is filtered per chapter, by the same policy the screen's progress uses. */
    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('view', TourProgress::class);
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    /** Een uitlegvraag herkennen en het hoofdstuk kiezen dat erbij hoort. */
    public static function difficulty(): int
    {
        return 2;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $catalog = app(TourCatalog::class);
        $tour_id = (string) $call->stringArgument('tour');
        $tour = in_array($tour_id, $catalog->offeredIds(), true) ? $catalog->tour($tour_id) : null;

        if ($tour === null) {
            return ToolResult::failed('Rondleiding "' . $tour_id . '" bestaat niet. Er zijn: ' . implode(', ', $catalog->offeredIds()) . '.');
        }

        $chapters = $catalog->chaptersFor($call->user, $tour_id);
        if ($chapters === []) {
            return ToolResult::failed('In deze rondleiding staat niets dat deze gebruiker mag zien.');
        }

        $listing = array_map(fn (array $chapter) => [
            'id' => $chapter['id'],
            'title' => $chapter['title'],
            'summary' => $chapter['summary'],
            'device' => $chapter['requires']['device'] ?? 'beide',
        ], $chapters);

        $chapter_id = $call->stringArgument('chapter');
        $offer = ['tour' => $tour_id, 'chapter' => null, 'label' => $tour['title'], 'device' => null];

        if (filled($chapter_id)) {
            $chapter = collect($chapters)->firstWhere('id', $chapter_id);
            if ($chapter === null) {
                return ToolResult::failedWith(
                    ['chapters' => $listing],
                    'Hoofdstuk "' . $chapter_id . '" hoort niet bij deze rondleiding of is er niet voor deze gebruiker. Kies uit chapters.',
                );
            }
            $offer = [
                'tour' => $tour_id,
                'chapter' => $chapter['id'],
                'label' => 'Laat zien: ' . $chapter['title'],
                'device' => $chapter['requires']['device'] ?? null,
            ];
        }

        return ToolResult::ok([
            'status' => 'rondleiding_aangeboden',
            'tour_offer' => $offer,
            'chapters' => $listing,
            'note' => 'Onder je antwoord staat nu een knop die dit start. Beschrijf de stappen niet nog eens en '
                . 'kondig de knop niet aan. Een hoofdstuk met device "phone" werkt alleen op een telefoon, '
                . '"desktop" alleen op een groot scherm.',
        ], 'Rondleiding klaargezet: ' . $offer['label'] . '.');
    }
}
