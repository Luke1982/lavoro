<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AssistantAnswer;
use App\Domain\Assistant\AssistantSession;
use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Assistant\ConversationPhotos;
use App\Domain\Assistant\ConversationReport;
use App\Domain\Assistant\TurnWatcher;
use App\Domain\Planning\Clock;
use App\Exceptions\AttachmentsUnreadable;
use App\Http\Requests\AssistantAskRequest;
use App\Http\Requests\AssistantConfirmRequest;
use App\Http\Requests\AssistantContinueRequest;
use App\Http\Requests\AssistantHistoryRequest;
use App\Http\Requests\AssistantPhotosRequest;
use App\Http\Requests\AssistantPromptStoreRequest;
use App\Http\Requests\AssistantReportRequest;
use App\Mail\AssistantConversationReportedMail;
use App\Models\AssistantPrompt;
use App\Models\AssistantQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The chat box's side of a conversation with the assistant.
 *
 * Every turn put to the model, asked or carried on after a click, is written
 * to assistant_questions: the question, the answer or why there was none, the
 * page, which tools ran, the choices offered and what it cost. That is what history() and
 * conversation() read back, and each person only ever sees their own.
 *
 * What goes to the model as the story so far is not read from there but sent
 * by the box with every turn, capped at AssistantSession::REMEMBERED_EXCHANGES:
 * a follow-up needs what was just said, not everything.
 *
 * That the history arrives from the browser costs nothing in safety. Every tool
 * authorises against the person asking on each call, so a doctored history can
 * mislead the assistant about what it once said but cannot reach a single record
 * its user could not already open.
 */
class AssistantController extends Controller
{
    private const HISTORY_LENGTH = 30;

    private const PREVIEW_CHARS = 400;

    public function ask(AssistantAskRequest $request, AssistantSession $session): JsonResponse
    {
        return $this->answer($request, $session, fn (TurnWatcher $watcher, string $conversation) => $session->ask(
            user: $request->user(),
            question: (string) $request->validated('question'),
            watcher: $watcher,
            conversation: $conversation,
            page: $request->validated('page'),
            history: $request->validated('history') ?? [],
            uploaded_pictures: $this->imagesAsAttachments($request->validated('images') ?? []),
            uploaded_files: $this->documentsAsAttachments($request->validated('documents') ?? []),
        ));
    }

    /**
     * Picks the conversation back up after something was confirmed.
     *
     * Clicking the button is an answer, and until now nothing said so: the model
     * only found out on the next thing somebody typed, so a conversation that had
     * just promised to add the task and plan a mechanic simply stopped.
     *
     * The prompt is written on the server rather than sent by the browser. What was
     * carried out is already in the history the box keeps, marked as done; this
     * only says "carry on", so a client cannot use it to put words in the model's
     * mouth.
     */
    public function proceed(AssistantContinueRequest $request, AssistantSession $session): JsonResponse
    {
        return $this->answer($request, $session, fn (TurnWatcher $watcher, string $conversation) => $session->proceed(
            user: $request->user(),
            watcher: $watcher,
            conversation: $conversation,
            history: $request->validated('history'),
            page: $request->validated('page'),
        ));
    }

    /**
     * One turn, asked or carried on, written down either way and answered with
     * everything the box draws under it.
     *
     * @param  Closure(TurnWatcher, string): AssistantAnswer  $asking
     */
    private function answer(AssistantAskRequest|AssistantContinueRequest $request, AssistantSession $session, Closure $asking): JsonResponse
    {
        $user = $request->user();
        $conversation = (string) $request->validated('conversation');

        if ($this->isSomebodyElses($user, $conversation)) {
            return response()->json(['message' => 'Dat gesprek bestaat niet, of het is niet van jou.'], 404);
        }

        $watcher = $session->watcher($user, $conversation);

        try {
            $answer = $asking($watcher, $conversation);
        } catch (AttachmentsUnreadable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelUnavailable $e) {
            $explained = $this->explain($e);
            $this->remember($request, $watcher, failure: $explained);

            return response()->json(['message' => $explained], 503);
        } catch (RuntimeException $e) {
            $this->remember($request, $watcher, failure: $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->remember($request, $watcher, $answer);

        return response()->json([
            'answer' => $answer->text,
            ...$watcher->toArray(),
            /**
             * Records the answer named that no tool returned. A prompt forbids
             * inventing them and a prompt is not a guarantee, so this is checked
             * and shown rather than trusted.
             */
            'unverified' => $session->unverified($answer, $watcher, $user),
        ]);
    }

    /**
     * The conversations this person has had, most recent first.
     *
     * Conversations, not questions. Listed one turn per row, "eerste" and "Nee het
     * is de eerste klant" sat there as separate things, and neither means anything
     * away from the thread it belonged to.
     *
     * Only their own: a transcript is a record of somebody's working day, and
     * being able to read the assistant is not the same as being able to read
     * everybody's use of it.
     */
    public function history(AssistantHistoryRequest $request): JsonResponse
    {
        $threads = AssistantQuestion::query()
            ->asked()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('conversation_id')
            ->selectRaw(
                'conversation_id, count(*) as turns, min(id) as opened_with, '
                . 'max(created_at) as last_at, max(id) as last_id'
            )
            ->groupBy('conversation_id')
            /**
             * The id breaks the tie. Two conversations touched in the same second
             * sort arbitrarily on the timestamp alone, so "most recent first" was
             * only true to the second — and in a busy minute, not true at all.
             */
            ->orderByDesc('last_at')
            ->orderByDesc('last_id')
            ->limit(self::HISTORY_LENGTH)
            ->get();

        /** One extra query for the opening lines rather than one per thread. */
        $openers = AssistantQuestion::query()
            ->whereIn('id', $threads->pluck('opened_with'))
            ->get()
            ->keyBy('id');

        return response()->json([
            'conversations' => $threads->map(function ($thread) use ($openers) {
                $opener = $openers->get($thread->opened_with);

                return [
                    'id' => $thread->conversation_id,
                    /** What was asked first, which is what a thread is about. */
                    'title' => $opener?->question,
                    'preview' => $opener?->answer === null
                        ? $opener?->failure
                        : mb_substr((string) $opener->answer, 0, self::PREVIEW_CHARS),
                    'turns' => (int) $thread->turns,
                    /**
                     * With an offset on it. The aggregate comes back as a bare
                     * "2026-07-30 14:18:40", which a browser reads as its own local
                     * time — so every thread in the list was stamped two hours early
                     * and one asked just after midnight was filed under yesterday.
                     */
                    'last_at' => $thread->last_at === null
                        ? null
                        : CarbonImmutable::parse($thread->last_at)->toIso8601String(),
                ];
            })->all(),
        ]);
    }

    /**
     * One conversation in full, so it can be picked up where it stopped.
     *
     * Continuations come too. Without them the thread reads oddly — an answer with
     * no question above it — and they are half of what was said.
     *
     * Choices come back, so an answer that ends in "kies hieronder" still has
     * something below it. Confirmations do not: their approvals have expired, and
     * a button that cannot work is worse than none.
     */
    public function conversation(AssistantHistoryRequest $request, string $conversation): JsonResponse
    {
        $turns = AssistantQuestion::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get();

        if ($turns->isEmpty()) {
            return response()->json(['message' => 'Dat gesprek bestaat niet, of het is niet van jou.'], 404);
        }

        return response()->json([
            'id' => $conversation,
            'turns' => $turns->map(fn (AssistantQuestion $turn) => [
                'question' => $turn->is_continuation ? '' : $turn->question,
                'answer' => $turn->answer,
                'failure' => $turn->failure,
                'tools' => $turn->tools ?? [],
                'choices' => $turn->choices ?? [],
            ])->all(),
        ]);
    }

    public function confirm(AssistantConfirmRequest $request, AssistantSession $session): JsonResponse
    {
        $confirmed = $session->confirm($request->user(), $request->validated('token'));

        if ($confirmed->result === null) {
            return response()->json(['message' => $confirmed->message], 422);
        }

        return response()->json([
            'ok' => $confirmed->succeeded(),
            'message' => $confirmed->message,
        ], $confirmed->succeeded() ? 200 : 422);
    }

    /**
     * The questions worth asking on the page somebody is looking at.
     *
     * Half of what this assistant can do is invisible from a blank prompt box —
     * nobody guesses it can read a typeplaatje off photos already on a werkbon.
     */
    public function prompts(AssistantHistoryRequest $request): JsonResponse
    {
        $context = $request->query('context');

        $prompts = AssistantPrompt::query()
            ->visibleTo($request->user())
            /** "all" is the management view; anything else is one page's shortlist. */
            ->when($context !== 'all', fn ($q) => $q->forContext(is_string($context) ? $context : null))
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'label', 'question', 'context', 'user_id']);

        return response()->json([
            'prompts' => $prompts->map(fn (AssistantPrompt $prompt) => [
                'id' => $prompt->id,
                'label' => $prompt->label,
                'question' => $prompt->question,
                /** Only your own can be removed; the shipped ones stay put. */
                'context' => $prompt->context,
                'mine' => $prompt->user_id !== null,
            ])->all(),
        ]);
    }

    public function storePrompt(AssistantPromptStoreRequest $request): JsonResponse
    {
        $prompt = AssistantPrompt::create([
            'user_id' => $request->user()->id,
            'label' => $request->validated('label'),
            'question' => $request->validated('question'),
            'context' => $request->validated('context'),
            'sort' => 100,
        ]);

        return response()->json([
            'message' => 'Vraag bewaard.',
            'prompt' => [
                'id' => $prompt->id,
                'label' => $prompt->label,
                'question' => $prompt->question,
                'context' => $prompt->context,
                'mine' => true,
            ],
        ], 201);
    }

    public function updatePrompt(AssistantPromptStoreRequest $request, AssistantPrompt $prompt): JsonResponse
    {
        if ($prompt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Deze vraag is niet van jou.'], 403);
        }

        $prompt->update($request->validated());

        return response()->json([
            'message' => 'Vraag bijgewerkt.',
            'prompt' => [
                'id' => $prompt->id,
                'label' => $prompt->label,
                'question' => $prompt->question,
                'context' => $prompt->context,
                'mine' => true,
            ],
        ]);
    }

    public function destroyPrompt(AssistantHistoryRequest $request, AssistantPrompt $prompt): JsonResponse
    {
        /** Yours to delete, and only yours: the shipped ones belong to everybody. */
        if ($prompt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Deze vraag is niet van jou.'], 403);
        }

        $prompt->delete();

        return response()->json(['message' => 'Vraag verwijderd.']);
    }

    /**
     * Puts the parked photos of a conversation into the person's own storage,
     * attached to whatever record the conversation settled.
     */
    public function keepPhotos(AssistantPhotosRequest $request, ConversationPhotos $photos): JsonResponse
    {
        $user = $request->user();
        $conversation = $request->validated('conversation');

        if (!$this->ownsConversation($user, $conversation)) {
            return response()->json(['message' => 'Dat gesprek bestaat niet, of het is niet van jou.'], 404);
        }

        $kept = $photos->keep($conversation, $user);

        if ($kept === null) {
            return response()->json(['message' => 'Er staan geen foto\'s meer klaar bij dit gesprek.'], 404);
        }

        if ($kept['count'] === 0) {
            return response()->json([
                'message' => 'Dit gesprek heeft geen machine, werkbon of product vastgelegd om de foto\'s '
                    . 'aan te hangen. Open het record en zet ze daar op, of gooi ze weg.',
            ], 422);
        }

        return response()->json([
            'message' => $kept['count'] . ' foto\'s bewaard bij ' . $kept['home'] . '.',
        ]);
    }

    public function discardPhotos(AssistantPhotosRequest $request, ConversationPhotos $photos): JsonResponse
    {
        $user = $request->user();
        $conversation = $request->validated('conversation');

        if (!$this->ownsConversation($user, $conversation)) {
            return response()->json(['message' => 'Dat gesprek bestaat niet, of het is niet van jou.'], 404);
        }

        $photos->discard($conversation);
        /** Whatever was borrowed goes with them; it was never anybody's to keep. */
        app(ConversationFiles::class)->discard($conversation);

        return response()->json(['message' => 'Foto\'s weggegooid.']);
    }

    private function ownsConversation(User $user, string $conversation): bool
    {
        return AssistantQuestion::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation)
            ->exists();
    }

    /**
     * A new conversation is nobody's yet; one with somebody else's turns in it
     * holds their parked photos and files, which a click could otherwise file on
     * a werkbon of yours.
     */
    private function isSomebodyElses(User $user, string $conversation): bool
    {
        return $conversation !== '' && AssistantQuestion::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', '!=', $user->id)
            ->exists();
    }

    /**
     * Writes one conversation out as a file somebody can hand over.
     *
     * A transcript copied off the screen leaves out the half that matters: what
     * the tools were called with and what they gave back. Every fault found in
     * this assistant so far was in there, behind prose that read perfectly well.
     */
    public function report(AssistantReportRequest $request, ConversationReport $reports): JsonResponse
    {
        $user = $request->user();
        $conversation = $request->validated('conversation');

        $reason = $request->validated('reason');
        $markdown = $reports->markdownFor($conversation, $user, $reason);

        if ($markdown === null) {
            return response()->json(['message' => 'Dat gesprek bestaat niet, of het is niet van jou.'], 404);
        }

        $name = Clock::toLocal(now())->format('Y-m-d_H-i') . '_' . $user->id . '_' . $conversation . '.md';
        $path = trim((string) config('assistant.reports_path', 'assistant-reports'), '/') . '/' . $name;

        try {
            Storage::disk(config('assistant.reports_disk', 'local'))->put($path, $markdown);
        } catch (Throwable $e) {
            Log::error('Kon gespreksrapport niet wegschrijven', ['exception' => $e]);

            return response()->json(['message' => 'Het rapport kon niet worden opgeslagen.'], 500);
        }

        /**
         * Delivered as well as filed. The disk is private, which is exactly where
         * nobody looks; a melding belongs in an inbox with the file attached. The
         * mail is best effort — the report is already safe on disk, and a broken
         * mailserver must not turn a successful melding into an error.
         */
        $mailed = $this->mailReport($markdown, $name, $user, $reason);

        return response()->json([
            'message' => 'Gesprek opgeslagen als ' . $name . ($mailed ? ' en gemaild.' : '.'),
            'path' => $path,
        ]);
    }

    /**
     * Data-URLs into attachments, dropping anything that does not decode.
     *
     * The shape is already validated; this is the base64 itself. A corrupt image
     * costs its own slot, never the question.
     *
     * @param  array<int, string>  $images
     * @return array<int, Attachment>
     */
    private function imagesAsAttachments(array $images): array
    {
        $attachments = [];

        foreach ($images as $index => $image) {
            $attachment = $this->asAttachment('foto-' . ($index + 1), (string) $image);

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * Files keep the name they arrived under, because the model is shown it as
     * the document's title. "SRK71-datasheet.pdf" tells it which machine it is
     * holding; "bestand-2" tells it nothing, and it will guess.
     *
     * @param  array<int, array{name: string, data: string}>  $documents
     * @return array<int, Attachment>
     */
    private function documentsAsAttachments(array $documents): array
    {
        $attachments = [];

        foreach ($documents as $index => $document) {
            $attachment = $this->asAttachment(
                (string) ($document['name'] ?? 'bestand-' . ($index + 1)),
                (string) ($document['data'] ?? ''),
            );

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /** One reader for both kinds: a data URL is a data URL. */
    private function asAttachment(string $name, string $data_url): ?Attachment
    {
        [$header, $base64] = explode(',', $data_url, 2) + [1 => ''];
        $media_type = str_replace(['data:', ';base64'], '', $header);

        if ($base64 === '' || base64_decode($base64, true) === false) {
            return null;
        }

        return new Attachment(name: $name, media_type: $media_type, base64: $base64);
    }

    private function mailReport(string $markdown, string $name, User $user, ?string $reason = null): bool
    {
        $to = config('assistant.reports_mail_to');

        if (blank($to)) {
            return false;
        }

        try {
            Mail::to($to)->send(new AssistantConversationReportedMail($markdown, $name, $user->name, $reason));

            return true;
        } catch (Throwable $e) {
            Log::warning('Kon gespreksrapport niet mailen', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Writes down what was asked and what came back.
     *
     * A failure is worth keeping too: "de assistent deed niets" and "de
     * assistent kon niet antwoorden" are different complaints, and only one of
     * them leaves a trace anywhere else.
     *
     * Never allowed to be the thing that breaks a question. Somebody who got
     * their answer should not see an error because writing it down failed.
     */
    private function remember(
        AssistantAskRequest|AssistantContinueRequest $request,
        TurnWatcher $watcher,
        ?AssistantAnswer $answer = null,
        ?string $failure = null,
    ): void {
        $continuation = $request instanceof AssistantContinueRequest;

        try {
            AssistantQuestion::create([
                'user_id' => $request->user()->id,
                'conversation_id' => $request->validated('conversation'),
                'question' => $continuation ? AssistantSession::CARRIED_ON : $request->validated('question'),
                'is_continuation' => $continuation,
                'answer' => $answer?->text,
                'failure' => $failure === null ? null : mb_substr($failure, 0, 255),
                /**
                 * The column holds 255 and the request accepts far more, so this
                 * is cut rather than left to fail. Failing means the catch below
                 * swallows it and the question is not written down at all — lost
                 * over a detail that is only there for context.
                 */
                'page' => mb_substr((string) $request->validated('page'), 0, 255) ?: null,
                'tools' => array_column($watcher->tools, 'name'),
                /**
                 * Only with an answer: a turn that failed never showed its
                 * pickers, and reopened later it must not offer them as live.
                 */
                'choices' => $answer === null || $watcher->choices === [] ? null : $watcher->choices,
                'rounds' => $answer?->tool_rounds ?? 0,
                'cost_micros' => $answer?->cost_micros ?? 0,
            ]);
        } catch (Throwable $e) {
            Log::error('Kon de vraag niet vastleggen', ['exception' => $e]);
        }
    }

    private function explain(ModelUnavailable $e): string
    {
        return match ($e->reason->value) {
            'no_credit' => 'Het AI-tegoed is op. Neem contact op met de beheerder.',
            'bad_credentials' => 'De AI-dienst weigert de sleutel. Neem contact op met de beheerder.',
            'unreachable' => 'De AI-dienst is niet bereikbaar. Probeer het zo nog eens.',
            default => 'De AI-dienst gaf een fout terug.',
        };
    }
}
