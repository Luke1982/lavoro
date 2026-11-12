<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\ConversationFiles;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\User;

/**
 * What the assistant takes from a file, written down the first time it reads it.
 *
 * A signed offerte is worked through one click at a time — customer, werkbon,
 * planning, contract — and every step after a click sent the whole pdf up
 * again, pages of it, to plan a mechanic from two of its lines. The note is
 * what goes up instead: the lines that matter, a fraction of the size.
 */
class NoteFileTool implements Tool
{
    /** A note longer than this has stopped being a note and become a copy. */
    private const MOST_CHARS = 6000;

    public static function name(): string
    {
        return 'note_file';
    }

    public function description(): string
    {
        return 'Legt vast wat je uit een meegestuurd bestand nodig hebt voor de rest van het gesprek. '
            . 'Doe dit zodra je een bestand voor het eerst leest, in dezelfde beurt: daarna gaat het '
            . 'bestand niet meer mee, alleen deze aantekening. Schrijf alles op wat de volgende stappen '
            . 'nodig hebben, met namen, nummers en getallen precies zoals ze erin staan.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => [
                    'type' => 'string',
                    'description' => 'De aantekening, kort en zakelijk.',
                ],
                'file_name' => [
                    'type' => 'string',
                    'description' => 'Welk meegestuurd bestand. Mag weg als er maar één is.',
                ],
            ],
            'required' => ['note'],
            'additionalProperties' => false,
        ];
    }

    /** It writes nothing but the conversation's own scratch paper. */
    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

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
        if (blank($call->conversation)) {
            return ToolResult::failed('Er hoort geen bestand bij dit gesprek.');
        }

        $note = trim((string) $call->stringArgument('note'));

        if ($note === '' || mb_strlen($note) > self::MOST_CHARS) {
            return ToolResult::failed('Een aantekening is niet leeg en hooguit ' . self::MOST_CHARS . ' tekens.');
        }

        $files = app(ConversationFiles::class);
        $name = $files->note($call->conversation, $call->stringArgument('file_name'), $note);

        if ($name === null) {
            return ToolResult::failed($files->whichOne($call->conversation));
        }

        return ToolResult::ok(['file' => $name], 'Aantekening bij ' . $name . ' bewaard.');
    }
}
