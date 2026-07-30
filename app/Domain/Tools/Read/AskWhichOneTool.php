<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\User;

/**
 * Puts a choice to the person, as something they can click.
 *
 * Several records matching one description is the ordinary case — three customers
 * in Ede with "dijk" in the name — and listing them in prose leaves two bad ways
 * out. The links go to the record, so following one navigates away from the
 * conversation that needed the answer. And answering in words does not reliably
 * land: "eerste" was met with a request to say which one.
 *
 * So the options come back as options. Nothing is looked up here and nothing is
 * changed; this only says what is being chosen between, and the box turns that
 * into buttons.
 */
class AskWhichOneTool implements Tool
{
    private const MOST_OPTIONS = 8;

    public static function name(): string
    {
        return 'ask_which_one';
    }

    public function description(): string
    {
        return 'Legt een keuze aan de gebruiker voor als meerdere records op de omschrijving passen — '
            . 'drie klanten met "dijk" in de naam, twee varianten van hetzelfde product. Gebruik dit in '
            . 'plaats van ze in een lijstje op te sommen: de gebruiker krijgt knoppen en kiest er één, '
            . 'zonder de tekst te hoeven overtypen. Zet in reference het echte nummer, zodat er daarna '
            . 'geen twijfel over is welk record bedoeld wordt.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Wat er gekozen moet worden, bijvoorbeeld "Welke klant bedoel je?".',
                ],
                'options' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => [
                                'type' => 'string',
                                'description' => 'Wat de gebruiker ziet, met genoeg erbij om ze te onderscheiden.',
                            ],
                            'reference' => [
                                'type' => 'string',
                                'description' => 'Het record waar deze keuze op neerkomt, bijvoorbeeld "klant #1037".',
                            ],
                            'link' => [
                                'type' => 'string',
                                'description' => 'Het pad naar dat record, bijvoorbeeld /customers/1037, zodat de '
                                    . 'gebruiker het kan bekijken voordat hij kiest. Laat weg als er geen record is.',
                            ],
                        ],
                        'required' => ['label', 'reference'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'De keuzes, in de volgorde waarin ze getoond worden.',
                ],
            ],
            'required' => ['question', 'options'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Alleen doorgeven wat er te kiezen is; het opzoeken is al gebeurd. */
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
        $question = $call->stringArgument('question');
        $options = $this->optionsIn($call->argument('options'));

        if (blank($question) || $options === []) {
            return ToolResult::failed('Geef een vraag en minstens twee keuzes met een label en een reference.');
        }

        /**
         * One option is not a choice. Asked to pick between a single thing the
         * person can only agree, which is a question not worth putting.
         */
        if (count($options) < 2) {
            return ToolResult::failed(
                'Bij één mogelijkheid hoef je niets te vragen: noem hem gewoon en ga verder.'
            );
        }

        return ToolResult::ok(
            [
                'status' => 'keuze_nodig',
                'question' => $question,
                'options' => $options,
                'note' => 'De gebruiker krijgt hier knoppen voor te zien, met een link per keuze om het '
                    . 'record te bekijken. Herhaal de keuzes niet in je antwoord en vraag er niet nog een '
                    . 'keer naar; zeg kort waarom je het vraagt en wacht.',
            ],
            'Keuze voorgelegd.',
        );
    }

    /**
     * A path inside this application, or nothing.
     *
     * Matched rather than read, and only ever a path — never a whole address. An
     * option is rendered as something clickable, so a value from a model that could
     * name any host at all would be a link into somebody else's site wearing this
     * one's clothes.
     */
    private function pathIn(mixed $given): ?string
    {
        if (!is_string($given) || !preg_match('#^/[a-z]+/\d+$#', trim($given))) {
            return null;
        }

        return trim($given);
    }

    /**
     * @return array<int, array{label: string, reference: string, link: ?string}>
     */
    private function optionsIn(mixed $given): array
    {
        if (!is_array($given)) {
            return [];
        }

        $options = [];

        foreach ($given as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = trim((string) ($option['label'] ?? ''));
            $reference = trim((string) ($option['reference'] ?? ''));

            if ($label === '' || $reference === '') {
                continue;
            }

            $options[] = [
                'label' => mb_substr($label, 0, 120),
                'reference' => mb_substr($reference, 0, 60),
                'link' => $this->pathIn($option['link'] ?? null),
            ];
        }

        return array_slice($options, 0, self::MOST_OPTIONS);
    }
}
