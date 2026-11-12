<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\User;

/**
 * Opens a file from earlier in the conversation again, on the model's own say.
 *
 * Once something was noted from a file, the file stops travelling with every
 * turn; the note goes instead. A note is never the whole file, so this is the
 * way back to it — taken when the note falls short, rather than paid for on
 * every turn in case it might.
 */
class ReadFileTool implements Tool
{
    public static function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Leest een bestand dat eerder in dit gesprek is meegestuurd opnieuw. Gebruik dit alleen als '
            . 'je aantekening niet zegt wat je nodig hebt. Je krijgt het bestand daarna te zien.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_name' => [
                    'type' => 'string',
                    'description' => 'Welk meegestuurd bestand. Mag weg als er maar één is.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** Only the conversation's own files, which this person sent. */
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

        $files = app(ConversationFiles::class);
        $file = $files->opened($call->conversation, $call->stringArgument('file_name'));

        if ($file === null) {
            return ToolResult::failed($files->whichOne($call->conversation));
        }

        /** Told, not worked around: answering anyway would be answering from memory. */
        if (!app(TalksToModel::class)->readsDocuments()) {
            return ToolResult::failed('Dit model kan het bestand niet lezen. Zeg dat tegen de gebruiker.');
        }

        return ToolResult::ok(['file' => $file->name], $file->name . ' opnieuw gelezen.')->showing([$file]);
    }
}
