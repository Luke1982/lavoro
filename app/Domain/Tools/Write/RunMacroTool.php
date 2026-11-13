<?php

namespace App\Domain\Tools\Write;

use App\Actions\ServiceOrders\RunServiceOrderMacroAction;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Macros\MacroAnswers;
use App\Domain\Macros\MacroRun;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Exceptions\Refusal;
use App\Models\Location;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Runs a macro on a werkbon for somebody who asked in the chat, through the same
 * checks and the same action as the "Macro uitvoeren" button on the werkbon: an
 * answer to every question on the path, the right for every step, and all of it
 * or none of it.
 */
class RunMacroTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    public static function name(): string
    {
        return 'run_macro';
    }

    public function description(): string
    {
        return 'Voert een macro uit op een werkbon. Haal hem eerst op met list_macros en beantwoord elke vraag '
            . 'op het pad; vraag de gebruiker wat je niet weet. Er gebeurt nog niets: de gebruiker krijgt een knop.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'De werkbon waarop de macro draait.',
                ],
                'macro_id' => [
                    'type' => 'integer',
                    'description' => 'De macro, uit list_macros.',
                ],
                'answers' => [
                    'type' => 'array',
                    'description' => 'Per vraag uit asks van list_macros: step_id, field en value.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'step_id' => ['type' => 'string'],
                            'field' => ['type' => 'string'],
                            'value' => ['description' => 'Het antwoord, in de vorm die bij kind hoort.'],
                        ],
                    ],
                ],
            ],
            'required' => ['service_order_id', 'macro_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('run', ServiceOrderMacro::class);
    }

    /** De antwoorden uit het gesprek bij de vragen van de macro zoeken, en vragen wat ontbreekt. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    /** Names the customer and every answer, so a wrong werkbon or a wrong number is seen before the click. */
    public function previewOf(ToolCall $call): string
    {
        $order_id = $call->integerArgument('service_order_id');
        $order = ServiceOrder::with('customer:id,name')->find($order_id);
        $macro = ServiceOrderMacro::find($call->integerArgument('macro_id'));
        $answered = $macro ? $macro->steps()->answered($this->answersOf($call, $macro)) : [];

        return 'Macro "' . ($macro->title ?? '?') . '" uitvoeren op werkbon #' . $order_id
            . ($order?->customer ? ' (' . $order->customer->name . ')' : '')
            . ($answered === [] ? '' : ': ' . implode('; ', $answered));
    }

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        $prepared = $this->prepared($call);

        return $prepared instanceof ToolResult ? $prepared : null;
    }

    public function execute(ToolCall $call): ToolResult
    {
        $prepared = $this->prepared($call);

        if ($prepared instanceof ToolResult) {
            return $prepared;
        }

        [$order, $macro, $run] = $prepared;

        try {
            app(RunServiceOrderMacroAction::class)->execute($order, $macro, $run);
        } catch (Refusal $refusal) {
            return ToolResult::failed($refusal->getMessage());
        }

        return ToolResult::ok(
            [
                'service_order_id' => $order->id,
                'customer' => $order->customer?->name,
                'macro' => $macro->title,
                'link' => '/serviceorders/' . $order->id,
            ],
            'Macro "' . $macro->title . '" uitgevoerd op werkbon #' . $order->id
                . ($order->customer ? ' van ' . $order->customer->name : '') . '.',
        );
    }

    /**
     * The werkbon, the macro and the checked answers — or why it cannot run. Found
     * before the button is offered and again when it is clicked.
     *
     * @return array{0: ServiceOrder, 1: ServiceOrderMacro, 2: MacroRun}|ToolResult
     */
    private function prepared(ToolCall $call): array|ToolResult
    {
        $order_id = $call->integerArgument('service_order_id');
        $order = ServiceOrder::visibleTo($call->user)->with('customer:id,name')->find($order_id);

        if ($order === null) {
            return ToolResult::notFound('Werkbon #' . $order_id);
        }

        $macro = ServiceOrderMacro::find($call->integerArgument('macro_id'));

        if ($macro === null) {
            return ToolResult::notFound('Macro #' . $call->integerArgument('macro_id'));
        }

        $refusal = app(RunServiceOrderMacroAction::class)->refusal($order, $macro);

        if ($refusal !== null) {
            return ToolResult::failed($refusal);
        }

        $answers = $this->answersOf($call, $macro);
        $checks = new MacroAnswers($order, $macro, $answers);

        if (!$checks->allow($call->user)) {
            return ToolResult::denied();
        }

        $validator = Validator::make(['answers' => $answers], $checks->rules(), [], $checks->names());

        if ($validator->fails()) {
            return $this->unanswered($validator->errors()->messages(), $order, $macro);
        }

        return [$order, $macro, new MacroRun($call->user, $validator->validated()['answers'] ?? [])];
    }

    /** @param  array<string, array<int, string>>  $errors */
    private function unanswered(array $errors, ServiceOrder $order, ServiceOrderMacro $macro): ToolResult
    {
        $keys = array_keys($errors);
        $reason = implode(' ', array_map(fn (array $messages) => $messages[0], $errors));

        if (collect($keys)->contains(fn (string $key) => str_ends_with($key, '.file'))) {
            $reason .= ' Een bestand moet in dit gesprek zijn meegestuurd; anders kan de gebruiker de macro '
                . 'uitvoeren met de knop "Macro uitvoeren" op de werkbon.';
        }

        $content = ['error' => $reason, 'asks' => $macro->steps()->questions()];

        if (collect($keys)->contains(fn (string $key) => str_ends_with($key, '.location'))) {
            $content['locations'] = Location::where('customer_id', $order->customer_id)->get(['id', 'title'])->all();
        }

        return ToolResult::failedWith($content, $reason);
    }

    /**
     * The answers as the run window sends them, step id to field to value, with
     * every file question answered by a file from this conversation.
     *
     * @return array<string, array<string, mixed>>
     */
    private function answersOf(ToolCall $call, ServiceOrderMacro $macro): array
    {
        $answers = [];

        foreach ((array) $call->argument('answers', []) as $given) {
            if (is_array($given) && is_scalar($given['step_id'] ?? null) && is_scalar($given['field'] ?? null)) {
                $answers[(string) $given['step_id']][(string) $given['field']] = $given['value'] ?? null;
            }
        }

        foreach ($macro->steps()->questions() as $question) {
            if ($question['kind'] === 'file') {
                $named = $answers[$question['step_id']][$question['field']] ?? null;
                $answers[$question['step_id']][$question['field']] = $this->chatFile($call, $named);
            }
        }

        return $answers;
    }

    /**
     * A file from the chat, as the upload the run window would have sent.
     *
     * It is already on this server's disk and never came in through a form, so
     * PHP's is-this-really-an-upload check — the one thing the last argument
     * switches off — has nothing to say about it. Type, size and the storage
     * limit are checked by the same rules as an upload.
     */
    private function chatFile(ToolCall $call, mixed $name): ?UploadedFile
    {
        $file = blank($call->conversation)
            ? null
            : app(ConversationFiles::class)->named($call->conversation, is_string($name) ? $name : null);

        return $file === null ? null : new UploadedFile($file['path'], $file['name'], null, null, true);
    }
}
