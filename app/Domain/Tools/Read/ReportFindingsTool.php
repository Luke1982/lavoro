<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\User;

/**
 * What was read off a photo, each part with how sure it is.
 *
 * Refusing is not the opposite of fabricating. Told never to guess, the
 * assistant went silent on a photo it had read perfectly well a week earlier —
 * "het merk is te wazig" about a plate that plainly says TOSOT — which helps
 * nobody: the person holding the camera can confirm a hunch in one second and
 * can do nothing at all with a refusal.
 *
 * So there is a third answer between certainty and silence, and it is
 * structured rather than prose: each finding carries a percentage, and the box
 * draws bars. A merk at 80 and a model at 45 tells somebody exactly where to
 * point the lens next. Prose hedging cannot do that — "waarschijnlijk" reads
 * the same at 55 as at 90.
 *
 * Every finding also says which machine it is about, because the alternative
 * grouping is the one the box had: a box per tool call. Six photos read in two
 * batches produced two boxes holding an outdoor unit with one indoor unit, and
 * a second indoor unit with a completely unrelated Tosot — a grouping that
 * describes when the model happened to speak, not what it was looking at.
 */
class ReportFindingsTool implements Tool
{
    public static function name(): string
    {
        return 'report_findings';
    }

    public function description(): string
    {
        return 'Meldt wat je van een foto hebt afgelezen, per onderdeel met hoe zeker je bent. '
            . 'Gebruik dit altijd als je een foto van een apparaat of typeplaatje bekijkt, ook als '
            . 'je twijfelt — juist dan. De gebruiker ziet balkjes met percentages en kan meteen '
            . 'bevestigen of corrigeren. Weet je iets echt niet, laat het dan weg in plaats van er '
            . 'een laag percentage op te plakken; alles wat je wél meent te zien hoort erin, ook op '
            . '40 procent. Zwijgen omdat je niet zeker bent helpt niemand.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'findings' => [
                    'type' => 'array',
                    'description' => 'De onderdelen die je hebt afgelezen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'subject' => [
                                'type' => 'string',
                                'description' => 'Welk apparaat dit is, kort en steeds hetzelfde geschreven: '
                                    . '"buitenunit", "binnenunit 1", "binnenunit 2". Alles wat je van hetzelfde '
                                    . 'apparaat afleest krijgt dezelfde naam, ook als het van verschillende '
                                    . 'foto\'s komt of je het in twee rondes meldt — het wordt per apparaat '
                                    . 'bij elkaar gezet. Twee foto\'s van één machine zijn één apparaat.',
                            ],
                            'field' => [
                                'type' => 'string',
                                'description' => 'Welk gegeven: merk, model, serienummer, productsoort, '
                                    . 'vermogen. Zet het apparaat er niet in — dat staat al in subject, en '
                                    . '"model buitenunit" naast subject "buitenunit" is dubbelop.',
                            ],
                            'value' => [
                                'type' => 'string',
                                'description' => 'Wat je afleest, letterlijk.',
                            ],
                            'confidence' => [
                                'type' => 'integer',
                                'description' => 'Hoe zeker, 0 tot 100. Wees eerlijk: een logo dat je '
                                    . 'meent te herkennen is 60 tot 80, letters die je echt kunt lezen '
                                    . 'zijn 95, een gok op basis van vorm is 30.',
                            ],
                            'image_ids' => [
                                'type' => 'array',
                                'description' => 'De image_id\'s van de foto\'s waar je dit vanaf leest, '
                                    . 'uit wat view_images teruggaf. De gebruiker ziet die foto\'s dan bij '
                                    . 'deze bevinding staan, dus hij kan zelf zien waar je het op baseert.',
                                'items' => ['type' => 'integer'],
                            ],
                            'basis' => [
                                'type' => 'string',
                                'description' => 'Waar het vandaan komt: "foto", "typeplaatje", '
                                    . '"internet" of "systeem".',
                            ],
                        ],
                        'required' => ['subject', 'field', 'value', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'unreadable' => [
                    'type' => 'array',
                    'description' => 'Wat je op deze foto juist níét kon lezen, zodat de gebruiker weet '
                        . 'waar hij de lens op moet richten.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['findings'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        /** Nothing is read and nothing is written: this only shapes an answer. */
        return true;
    }

    /** Reading a plate and pricing your own certainty is the judgement. */
    public static function difficulty(): int
    {
        return 6;
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
        $findings = $this->findingsIn($call);

        if ($findings === []) {
            return ToolResult::failed(
                'Geef minstens één bevinding met een veld, een waarde en een percentage.'
            );
        }

        $unreadable = collect($call->argument('unreadable'))
            ->filter(fn ($line) => is_string($line) && trim($line) !== '')
            ->map(fn (string $line) => trim($line))
            ->values()
            ->all();

        /**
         * The nudge fires where the gap is, not in a prompt somewhere above.
         *
         * Told in the system prompt to look up candidates it kept not doing it —
         * "een gok op vorm, niet op tekst" — while the search that finds them
         * takes one call: brand and bouwvorm are text, and text is exactly what
         * it can look up. A monteur holding the machine picks his model out of
         * three in seconds and can do nothing with "onleesbaar".
         */
        $missing = $unreadable === [] ? '' : ' Er staat iets bij unreadable: zoek nu op internet welke '
            . 'modellen van dat merk die bouwvorm hebben en meld die als kandidaten met report_findings, '
            . 'met een percentage en de bron erbij. Laat het niet bij "onleesbaar" — dat helpt niemand.';

        return ToolResult::ok(
            [
                'findings' => $findings,
                'unreadable' => $unreadable,
                'note' => 'De gebruiker ziet deze bevindingen als balkjes met percentages, per apparaat '
                    . 'bij elkaar gezet — meld hetzelfde apparaat dus altijd onder dezelfde subject, ook '
                    . 'in een tweede ronde. Herhaal ze '
                    . 'niet in je antwoord; zeg kort wat je conclusie is, waar je het minst zeker over '
                    . 'bent, en wat een betere foto zou oplossen. Ga niet verder aanmaken zolang iets '
                    . 'onder de 70 procent zit zonder dat de gebruiker het bevestigt.' . $missing,
            ],
            count($findings) . ' bevinding(en) gemeld.',
        );
    }

    /**
     * @return array<int, array{field: string, value: string, confidence: int, basis: string}>
     */
    private function findingsIn(ToolCall $call): array
    {
        $given = $call->argument('findings');

        if (!is_array($given)) {
            return [];
        }

        $findings = [];

        foreach ($given as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $field = trim((string) ($finding['field'] ?? ''));
            $value = trim((string) ($finding['value'] ?? ''));

            if ($field === '' || $value === '') {
                continue;
            }

            $findings[] = [
                'subject' => mb_substr(trim((string) ($finding['subject'] ?? '')), 0, 40) ?: 'Apparaat',
                'field' => mb_substr($field, 0, 40),
                'value' => mb_substr($value, 0, 120),
                /** Clamped rather than refused: 120 per cent is enthusiasm, not an error. */
                'confidence' => max(0, min(100, (int) ($finding['confidence'] ?? 0))),
                'basis' => mb_substr(trim((string) ($finding['basis'] ?? 'foto')), 0, 20) ?: 'foto',
                'image_ids' => collect($finding['image_ids'] ?? [])
                    ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ];
        }

        return $findings;
    }
}
