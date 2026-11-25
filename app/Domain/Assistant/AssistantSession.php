<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
use App\Exceptions\AttachmentsUnreadable;
use App\Models\User;
use Closure;

/**
 * One conversation with the assistant as the chat box holds it: a question, the
 * click on a confirmation, and carrying on after that click.
 *
 * The box, the command line and the eval all go through here, so they share the
 * prompt, the sorting and the route to a model. Writing the question down and
 * checking whose conversation it is stay with the box.
 */
class AssistantSession
{
    /** Stands in for the question on the turns that follow a confirmation. */
    public const CARRIED_ON = '(verder na bevestiging)';

    /**
     * How many earlier exchanges go back to the model. A follow-up needs what was
     * just said rather than everything ever asked; twelve, because working
     * through a signed offerte is a dozen steps of one thread.
     *
     * The box (resources/js/Utilities/assistantHistory.js), the requests that
     * accept its history and the eval all keep to these, held together by
     * tests/fixtures/assistant-history.json.
     */
    public const REMEMBERED_EXCHANGES = 12;

    /** The longest answer the history carries back, in characters. */
    public const REMEMBERED_ANSWER_CHARS = 8000;

    /** The longest question that can be asked, and so the longest one the history carries back. */
    public const QUESTION_CHARS = 2000;

    private const CARRY_ON_PROMPT = 'De actie hierboven is nu daadwerkelijk uitgevoerd. Ga verder met wat er nog '
        . 'open staat uit dit gesprek: is dat een volgende stap, zet die dan klaar. Is alles '
        . 'gedaan, zeg dan kort wat er nu staat en vraag of er nog iets moet gebeuren. '
        . 'Doe niets opnieuw wat al is uitgevoerd.';

    public function __construct(
        private readonly AssistantLoop $loop,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly PageContext $pages,
        private readonly QuestionSorter $sorter,
        private readonly ConversationFacts $facts,
        private readonly ConversationFiles $parked_files,
        private readonly ConversationPhotos $parked_photos,
        private readonly ModelPicker $models,
        private readonly ReferenceCheck $references,
    ) {}

    /** Collects what one turn's tools did, and teaches the conversation what they found. */
    public function watcher(User $user, ?string $conversation): TurnWatcher
    {
        $thread = self::thread($conversation);

        return new TurnWatcher(fn (ToolResult $result) => $this->facts->learn($thread, $user, $result));
    }

    /**
     * A question, with what came along with it. A photo or a file stays with the
     * conversation rather than with the one message it came on, but only goes up
     * again when the question is about it: six photographs sent along with "kun
     * je die modellen opzoeken" cost tens of times what the question was worth.
     *
     * @param  array<int, array{question: string, answer: string}>  $history
     * @param  array<int, Attachment>  $uploaded_pictures
     * @param  array<int, Attachment>  $uploaded_files
     *
     * @throws AttachmentsUnreadable
     */
    public function ask(
        User $user,
        string $question,
        TurnWatcher $watcher,
        ?string $conversation = null,
        ?string $page = null,
        array $history = [],
        array $uploaded_pictures = [],
        array $uploaded_files = [],
        ?int $difficulty = null,
        ?int $max_rounds = null,
        ?Closure $on_text = null,
    ): AssistantAnswer {
        $thread = self::thread($conversation);

        $pictures = $uploaded_pictures === [] && $thread !== null && NeedsEyes::inQuestion($question)
            ? $this->parked_photos->parked($thread)
            : $uploaded_pictures;

        $files = $uploaded_files === [] && $thread !== null && NeedsTheFile::inQuestion($question)
            ? $this->parked_files->unnoted($thread)
            : $uploaded_files;

        $this->refuseUnreadable($pictures, $files);

        /**
         * What arrived with this question, never what was fetched back for it:
         * parked a second time, a file was there twice, and asked "which one"
         * the conversation could no longer tell its own copies apart.
         */
        if ($thread !== null) {
            $this->parked_photos->stash($thread, $uploaded_pictures);
            $this->parked_files->stash($thread, $uploaded_files);
        }

        return $this->loop->ask(...[
            'user' => $user,
            'question' => $question,
            'system' => SystemPrompt::text(),
            'context' => $this->context($user, $thread, $page),
            'history' => $history,
            'onTool' => $watcher(...),
            'onText' => $on_text,
            'difficulty' => $difficulty ?? $this->sorter->difficultyFor($question, $user, $this->registry),
            'attachments' => array_merge($pictures, $files),
            'needs_vision' => $this->willLookAtPictures($thread, $question),
            'needs_documents' => $this->hasFiles($thread),
            'conversation' => $thread,
            ...($max_rounds === null ? [] : ['max_rounds' => $max_rounds]),
        ]);
    }

    /**
     * Picks the conversation back up after something was confirmed. Not a fresh
     * question, so it is not priced as one: what comes next is usually the step
     * the assistant already named.
     *
     * A file goes back up after a click only until something was noted from it.
     * A signed offerte is worked through one click at a time, and the steps near
     * the end need lines from it that have long scrolled out of the history.
     *
     * @param  array<int, array{question: string, answer: string}>  $history
     *
     * @throws AttachmentsUnreadable
     */
    public function proceed(
        User $user,
        TurnWatcher $watcher,
        ?string $conversation = null,
        array $history = [],
        ?string $page = null,
        ?Closure $on_text = null,
    ): AssistantAnswer {
        $thread = self::thread($conversation);
        $files = $thread === null ? [] : $this->parked_files->unnoted($thread);

        $this->refuseUnreadable([], $files);

        return $this->loop->ask(
            user: $user,
            question: self::CARRY_ON_PROMPT,
            system: SystemPrompt::text(),
            context: $this->context($user, $thread, $page),
            history: $history,
            onTool: $watcher(...),
            onText: $on_text,
            attachments: $files,
            needs_vision: $this->willLookAtPictures($thread, null),
            needs_documents: $this->hasFiles($thread),
            conversation: $thread,
        );
    }

    /**
     * Carries out something already agreed to, unless the token has expired or
     * belongs to somebody else.
     *
     * No model runs here. The token holds the tool and its arguments, so what the
     * person read is what happens — sending it back through the assistant would
     * put a language model between the words on screen and the act they approved.
     * What it made is the firmest fact the conversation can learn: a person read
     * it, agreed to it, and the code carried it out.
     */
    public function confirm(User $user, string $token): ConfirmedAction
    {
        $approval = ConfirmationToken::decode($token, $user);

        if ($approval === null) {
            return ConfirmedAction::expired();
        }

        $result = $this->executor->run(new ToolCall(
            name: $approval->tool,
            arguments: $approval->arguments,
            user: $user,
            confirmation_token: $token,
            conversation: $approval->conversation,
        ));

        $this->facts->learn($approval->conversation, $user, $result);

        return ConfirmedAction::carriedOut($approval, $result);
    }

    /**
     * The record references in the answer that no tool returned. The web search's
     * reading list counts as seen: a model number just read on a spec sheet is
     * not an invention.
     *
     * @return array<int, string>
     */
    public function unverified(AssistantAnswer $answer, TurnWatcher $watcher, User $user): array
    {
        $seen = array_merge($watcher->seen, $answer->searched);

        return $this->references->report($answer->text, $seen, $user->id);
    }

    /** No conversation and an empty one are the same thing, said once here. */
    private static function thread(?string $conversation): ?string
    {
        return filled($conversation) ? $conversation : null;
    }

    /**
     * Refused up front rather than silently dropped. An attachment handed to a
     * provider that cannot open it gets answered in the same confident voice as
     * one that was read, which is fabrication with extra steps.
     *
     * @param  array<int, Attachment>  $pictures
     * @param  array<int, Attachment>  $files
     */
    private function refuseUnreadable(array $pictures, array $files): void
    {
        if ($pictures !== [] && !$this->models->canSee()) {
            throw new AttachmentsUnreadable('Er is geen model ingesteld dat foto\'s kan bekijken.');
        }

        if ($files !== [] && !$this->models->canRead()) {
            throw new AttachmentsUnreadable('Er is geen model ingesteld dat bestanden kan lezen.');
        }
    }

    private function context(User $user, ?string $thread, ?string $page): string
    {
        return SystemPrompt::context(
            $user,
            $this->pages->describe($page),
            $this->facts->sentence($this->facts->for($thread, $user)),
            $thread === null ? '' : $this->parked_files->reminder($thread),
        );
    }

    /** A conversation that had a file stays with a provider that can open one. */
    private function hasFiles(?string $thread): bool
    {
        return $thread !== null && $this->parked_files->has($thread);
    }

    /**
     * Whether this turn is going to end up looking at pictures, decided before the
     * first round because a conversation cannot change providers halfway: a
     * model's own turn goes back to it as it came. Parked photos are certain; the
     * words are a guess, and a cheap one to get wrong. A conversation that had
     * photos stays with the provider that can see, or "kan je niet op internet
     * zoeken?" goes to the blind model and is answered truthfully for that one.
     */
    private function willLookAtPictures(?string $thread, ?string $question): bool
    {
        return NeedsEyes::inQuestion($question)
            || ($thread !== null && $this->parked_photos->has($thread));
    }
}
