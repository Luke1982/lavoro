<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\ConversationFacts;
use App\Domain\Assistant\PageContext;
use App\Domain\Assistant\QuestionSorter;
use App\Domain\Assistant\ReferenceCheck;
use App\Domain\Planning\Clock;
use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
use App\Http\Requests\AssistantAskRequest;
use App\Http\Requests\AssistantConfirmRequest;
use App\Http\Requests\AssistantContinueRequest;
use App\Http\Requests\AssistantHistoryRequest;
use App\Models\AssistantQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A conversation that lives only as long as the box is open.
 *
 * The thread on screen is the thread that gets sent, so nothing is stored here
 * and there is no transcript to decide retention rules for. It is capped at a
 * handful of exchanges: a follow-up needs what was just said, not everything.
 *
 * That the history arrives from the browser costs nothing in safety. Every tool
 * authorises against the person asking on each call, so a doctored history can
 * mislead the assistant about what it once said but cannot reach a single record
 * its user could not already open.
 */
class AssistantController extends Controller
{
    /** Stands in for a question on the turns that follow a confirmation. */
    private const CARRIED_ON = '(verder na bevestiging)';

    private const HISTORY_LENGTH = 30;

    private const PREVIEW_CHARS = 400;

    public function ask(
        AssistantAskRequest $request,
        AssistantLoop $loop,
        ToolRegistry $registry,
        PageContext $pages,
        QuestionSorter $sorter,
        ConversationFacts $facts,
    ): JsonResponse {
        $user = $request->user();
        $tools = [];
        $pending = [];
        $choices = [];
        $seen = [];

        try {
            $answer = $loop->ask(
                user: $user,
                question: $request->validated('question'),
                system: $this->systemPrompt(),
                context: $this->context(
                    $user,
                    $pages->describe($request->validated('page')),
                    $facts->sentence($facts->for($request->validated('conversation'), $user)),
                ),
                history: $request->validated('history') ?? [],
                onTool: $this->toolWatcher(
                    $tools,
                    $pending,
                    $choices,
                    $seen,
                    fn (ToolResult $result) => $facts->learn($request->validated('conversation'), $user, $result),
                ),
                difficulty: $sorter->difficultyFor($request->validated('question'), $user, $registry),
            );
        } catch (ModelUnavailable $e) {
            $this->remember($request, $user, $tools, failure: $this->explain($e));

            return response()->json(['message' => $this->explain($e)], 503);
        } catch (RuntimeException $e) {
            $this->remember($request, $user, $tools, failure: $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->remember($request, $user, $tools, answer: $answer->text, rounds: $answer->tool_rounds, cost: $answer->cost_micros);

        return response()->json([
            'answer' => $answer->text,
            'tools' => $tools,
            'pending' => $pending,
            'choices' => $choices,
            /**
             * Records the answer named that no tool returned. A prompt forbids
             * inventing them and a prompt is not a guarantee, so this is checked
             * and shown rather than trusted.
             */
            'unverified' => app(ReferenceCheck::class)->report($answer->text, $seen, $user->id),
        ]);
    }

    /**
     * How hard to treat this question as being.
     *
     * The question decides, capped by what this person's tools could support:
     * asking something that sounds like diagnosis is no reason to buy a clever
     * model for somebody who can only reach a customer lookup.
     *
     * If the question cannot be read, the old behaviour stands — the hardest tool
     * available. That errs expensive, which is the right direction for a fallback:
     * a dear answer beats a poor one.
     */

    /**
     * Picks the conversation back up after something was confirmed.
     *
     * Clicking the button is an answer, and until now nothing said so: the model
     * only found out on the next thing somebody typed, so a conversation that had
     * just promised to add the task and plan a mechanic simply stopped. It knew
     * what came next and had no way to be asked.
     *
     * The prompt is written here rather than sent by the browser. What was carried
     * out is already in the history the box keeps, marked as done; this only says
     * "carry on", so a client cannot use it to put words in the model's mouth.
     */
    public function proceed(
        AssistantContinueRequest $request,
        AssistantLoop $loop,
        ToolRegistry $registry,
        PageContext $pages,
        ConversationFacts $facts,
    ): JsonResponse {
        /**
         * Carrying on is not a fresh question, and what comes next is usually the
         * step the assistant already named, so this keeps the capable model rather
         * than pricing a sentence nobody wrote.
         */
        $user = $request->user();
        $tools = [];
        $pending = [];
        $choices = [];
        $ignored = [];

        try {
            $answer = $loop->ask(
                user: $user,
                question: 'De actie hierboven is nu daadwerkelijk uitgevoerd. Ga verder met wat er nog '
                    . 'open staat uit dit gesprek: is dat een volgende stap, zet die dan klaar. Is alles '
                    . 'gedaan, zeg dan kort wat er nu staat en vraag of er nog iets moet gebeuren. '
                    . 'Doe niets opnieuw wat al is uitgevoerd.',
                system: $this->systemPrompt(),
                context: $this->context(
                    $user,
                    $pages->describe($request->validated('page')),
                    $facts->sentence($facts->for($request->validated('conversation'), $user)),
                ),
                onTool: $this->toolWatcher(
                    $tools,
                    $pending,
                    $choices,
                    $ignored,
                    fn (ToolResult $result) => $facts->learn($request->validated('conversation'), $user, $result),
                ),
                history: $request->validated('history'),
            );
        } catch (ModelUnavailable $e) {
            $this->write($user, self::CARRIED_ON, $request->validated('page'), $tools, $request->validated('conversation'), failure: $this->explain($e), continuation: true);

            return response()->json(['message' => $this->explain($e)], 503);
        } catch (RuntimeException $e) {
            $this->write($user, self::CARRIED_ON, $request->validated('page'), $tools, $request->validated('conversation'), failure: $e->getMessage(), continuation: true);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->write(
            $user,
            self::CARRIED_ON,
            $request->validated('page'),
            $tools,
            $request->validated('conversation'),
            answer: $answer->text,
            rounds: $answer->tool_rounds,
            cost: $answer->cost_micros,
            continuation: true,
        );

        return response()->json([
            'answer' => $answer->text,
            'tools' => $tools,
            'pending' => $pending,
            'choices' => $choices,
        ]);
    }

    /**
     * Watches the tool calls of one turn, and picks out anything the box has to
     * put in front of somebody: an action waiting to be agreed to, or a choice
     * waiting to be made.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, array<string, mixed>>  $pending
     * @param  array<int, array<string, mixed>>  $choices
     */
    private function toolWatcher(
        array &$tools,
        array &$pending,
        array &$choices,
        array &$seen = [],
        ?Closure $learn = null,
    ): Closure {
        return function (
            string $name,
            array $arguments,
            bool $failed,
            ?ToolResult $result = null,
        ) use (
            &$tools,
            &$pending,
            &$choices,
            &$seen,
            $learn,
        ) {
            $tools[] = ['name' => $name, 'arguments' => $arguments, 'failed' => $failed];

            if ($learn !== null && $result !== null) {
                $learn($result);
            }
            $seen[] = $result?->toModelContent() ?? '';
            $status = is_array($result?->content) ? ($result->content['status'] ?? null) : null;

            if ($status === 'bevestiging_nodig') {
                $pending[] = [
                    'tool' => $name,
                    'preview' => $result->content['preview'] ?? null,
                    'arguments' => $result->content['proposed'] ?? [],
                    'token' => $result->content['confirmation_token'],
                ];
            }

            if ($status === 'keuze_nodig') {
                $choices[] = [
                    'question' => $result->content['question'],
                    'options' => $result->content['options'],
                ];
            }

            /**
             * A lookup can offer a choice of its own, without the model deciding to
             * ask. Taken from the result rather than from what it says about it.
             */
            if (is_array($result?->content) && isset($result->content['choice']['options'])) {
                $choices[] = [
                    'question' => $result->content['choice']['question'] ?? 'Welke bedoel je?',
                    'options' => $result->content['choice']['options'],
                ];
            }
        };
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
     * Proposals and choices are deliberately not restored. Their approvals have
     * expired and their moment has passed; offering a button that cannot work is
     * worse than not offering one.
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
            ])->all(),
        ]);
    }

    /**
     * Carries out something already agreed to.
     *
     * No model runs here. The token holds the tool and its arguments, so what the
     * person read is what happens — sending it back through the assistant would
     * put a language model between the words on screen and the act they approved.
     */
    public function confirm(
        AssistantConfirmRequest $request,
        ToolExecutor $executor,
        ConversationFacts $facts,
    ): JsonResponse {
        $user = $request->user();
        $approval = ConfirmationToken::decode($request->validated('token'), $user);

        if ($approval === null) {
            return response()->json([
                'message' => 'Deze bevestiging is verlopen of hoort niet bij jou. Stel de vraag opnieuw.',
            ], 422);
        }

        $result = $executor->run(new ToolCall(
            name: $approval->tool,
            arguments: $approval->arguments,
            user: $user,
            confirmation_token: $request->validated('token'),
        ));

        /**
         * The firmest fact there is. A person read it and agreed to it and the
         * code carried it out, so the werkbon it just made is the werkbon this
         * conversation is about — no lookup could establish that better.
         */
        $facts->learn($request->validated('conversation'), $user, $result);

        return response()->json([
            'ok' => !$result->is_error,
            'message' => $result->summary ?? ($result->is_error ? 'Het is niet gelukt.' : 'Gelukt.'),
        ], $result->is_error ? 422 : 200);
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
     *
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function remember(
        AssistantAskRequest $request,
        User $user,
        array $tools,
        ?string $answer = null,
        ?string $failure = null,
        int $rounds = 0,
        int $cost = 0,
    ): void {
        $this->write(
            $user,
            $request->validated('question'),
            $request->validated('page'),
            $tools,
            $request->validated('conversation'),
            $answer,
            $failure,
            $rounds,
            $cost,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function write(
        User $user,
        string $question,
        ?string $page,
        array $tools,
        ?string $conversation = null,
        ?string $answer = null,
        ?string $failure = null,
        int $rounds = 0,
        int $cost = 0,
        bool $continuation = false,
    ): void {
        try {
            AssistantQuestion::create([
                'user_id' => $user->id,
                'conversation_id' => $conversation,
                'question' => $question,
                'is_continuation' => $continuation,
                'answer' => $answer,
                'failure' => $failure === null ? null : mb_substr($failure, 0, 255),
                /**
                 * The column holds 255 and the request accepts far more, so this
                 * is cut rather than left to fail. Failing means the catch below
                 * swallows it and the question is not written down at all — lost
                 * over a detail that is only there for context.
                 */
                'page' => mb_substr((string) $page, 0, 255) ?: null,
                'tools' => array_column($tools, 'name'),
                'rounds' => $rounds,
                'cost_micros' => $cost,
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

    /**
     * Frozen for everybody, every day: this sits behind the cache marker with the
     * tool definitions, and a cached prefix only earns its keep while it stays
     * byte-for-byte identical.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'Je bent de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.',
            'Je schrijft altijd Nederlands, ook de zinnetjes tussendoor waarin je zegt wat je gaat opzoeken.',
            'Je antwoordt kort en concreet. Gebruik korte alinea\'s of een opsomming, geen tabellen.',
            '',
            'Gebruik de tools om echte gegevens op te halen. Verzin nooit een werkbonnummer,',
            'klantnaam of datum: als je het niet uit een tool hebt, zeg je dat je het niet weet.',
            'Een tool geeft alleen terug wat deze gebruiker mag zien, dus een leeg resultaat',
            'betekent "niets gevonden of niets zichtbaar", niet "het bestaat niet".',
            '',
            'Je kunt alleen bij wat je tools je geven, en je kunt niets wijzigen: je leest mee,',
            'je voert niets uit. Word je iets gevraagd waar geen tool voor is, zeg dan in één zin',
            'wat je wél kunt op dat vlak en wat niet, in plaats van te gokken of om meer uitleg',
            'te vragen. Bijvoorbeeld: "Ik kan de tijdlijn van een werkbon doorzoeken, maar ik kan',
            'geen offertes opstellen." Noem daarbij geen toolnamen; beschrijf het in gewone woorden.',
            'Vraag alleen om verduidelijking als de vraag zelf onduidelijk is, niet als hij',
            'duidelijk maar onmogelijk is.',
            '',
            'Verwijs je naar een record, maak er dan een link van in markdown, zodat de',
            'gebruiker er meteen heen kan. Gebruik precies deze paden:',
            '- werkbon: [#296](/serviceorders/296)',
            '- storing: [#327](/tickets/327)',
            '- machine: [#173](/assets/173)',
            '- klant: [Bouwbedrijf Kreeft](/customers/4)',
            '- project: [#12](/projects/12)',
            'Alleen voor records waarvan je het nummer echt uit een tool hebt. Verzin nooit',
            'een link, en gebruik geen andere paden dan deze.',
            '',
            'Vul niet in wat iemand niet gezegd heeft. "Ik moet morgen een airco installeren bij die',
            'klant" kan betekenen: laat zien wat er al staat, of maak iets nieuws aan. Ga dan niet',
            'zes dingen opzoeken om te raden wat bedoeld wordt — vraag het, met ask_which_one, en',
            'zoek daarna één keer het goede op. Datum, duur, monteur en product zijn ook geen dingen',
            'om aan te nemen: die vraag je.',
            '',
            'Passen er meerdere records op wat iemand zoekt, som ze dan niet op maar leg de keuze',
            'voor met ask_which_one. Een link alleen is niet genoeg: die gaat naar het record en',
            'haalt de gebruiker daarmee uit het gesprek, en een antwoord als "de eerste" is niet',
            'betrouwbaar te plaatsen. Geef bij elke keuze wel het pad mee in link, zodat iemand het',
            'record kan bekijken voordat hij kiest.',
            '',
            'Wil je iets vastleggen of wijzigen, roep dan de bijbehorende tool aan zodra je de',
            'gegevens hebt. Die tools wijzigen uit zichzelf nog niets: ze geven terug dat er',
            'bevestiging nodig is, en het systeem legt de gebruiker een knop voor. Vraag nooit',
            'zelf in tekst om toestemming zonder de tool aan te roepen — dan is er niets om te',
            'bevestigen en gebeurt er ook niets. Vat na het aanroepen kort samen wat er zou',
            'gebeuren, zodat de gebruiker weet waar hij ja op zegt.',
            '',
            'Staat er eerder in het gesprek "[al uitgevoerd]", dan is dat gebeurd en bestaat het.',
            'Bied het niet opnieuw aan en roep de tool er niet nog eens voor aan: dat maakt een',
            'tweede exemplaar. Verwijs naar het nummer dat je terugkreeg en ga verder met wat',
            'er nog open staat.',
            '',
            'Roep een tool nooit twee keer voor dezelfde handeling in één antwoord.',
            '',
            'Hoort een afspraak bij een werkbon die nog niet bestaat, plan hem dan in één keer in',
            'met create_service_order_for_customer_id. Twee losse acties worden namelijk twee losse',
            'records: een werkbon zonder afspraak en een afspraak die bij niets hoort.',
            '',
            'Een werkbon zonder taken zegt niet wat er gedaan moet worden. Gaat het om plaatsen,',
            'vervangen of leveren van apparatuur, vraag dan welk product het is en hoeveel, zoek',
            'het product op zodat je het echte nummer hebt, en zet het als taak op de werkbon.',
            'Vraag dat vóórdat je iets voorstelt: "installatie airco" is geen opdracht waar een',
            'monteur mee op pad kan.',
        ]);
    }

    /**
     * Everything that changes per person, per day and per page, kept out of the
     * system prompt so the cached prefix stays byte-for-byte identical.
     */
    private function context(User $user, string $page, string $settled = ''): string
    {
        $lines = ['Je praat met ' . $user->name . '. Vandaag is ' . Clock::today() . '.'];

        if ($page !== '') {
            $lines[] = $page;
        }

        /**
         * What earlier turns established, so the thread does not have to be held
         * in prose. Prose came loose: a werkbon numbered #4 turned into customer
         * #4 and the conversation carried on with the wrong company.
         */
        if ($settled !== '') {
            $lines[] = $settled;
        }

        return implode(' ', $lines);
    }
}
