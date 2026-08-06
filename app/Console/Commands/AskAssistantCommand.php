<?php

namespace App\Console\Commands;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Assistant\NeedsEyes;
use App\Domain\Assistant\QuestionSorter;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantUsage;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Asks the assistant one question from the terminal and lets it use the tools.
 *
 * This exists to answer the question the rest of the assistant rests on: does the
 * model pick the right tool from the descriptions we wrote? That is cheaper to
 * find out here than behind a chat window, and what it teaches changes what the
 * chat window should be.
 */
class AskAssistantCommand extends Command
{
    protected $signature = 'assistant:ask
        {question : The question, in Dutch}
        {--user= : Id of the user to act as}
        {--steps=6 : How many tool rounds to allow before giving up}
        {--show-results : Print what each tool returned, not just what was asked}
        {--difficulty= : Force a difficulty from 1 to 10 instead of letting the tools decide}';

    protected $description = 'Stelt de assistent één vraag vanaf de commandline.';

    public function handle(AssistantLoop $loop, ToolRegistry $registry): int
    {
        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        /**
         * Routed the way a question asked in the box is routed. Sorting used to be
         * skipped here, so the one tool anybody would reach for to find out why
         * everything lands on the dear model reported a route production does not
         * take — and reported it confidently.
         */
        $difficulty = (int) ($this->option('difficulty')
            ?: app(QuestionSorter::class)->difficultyFor($this->argument('question'), $user, $registry));
        $provider = app(ModelPicker::class)->providerFor($difficulty);

        if (blank(config('assistant.providers.' . $provider . '.api_key'))) {
            $this->error('Er is geen API-sleutel ingesteld voor aanbieder "' . $provider . '".');

            return self::FAILURE;
        }

        $this->line('<comment>Als:</comment> ' . $user->name . ' (' . ToolProfile::forUser($user)->value
            . ', ' . count($registry->definitionsFor($user)) . ' tools)'
            . ' <comment>moeilijkheid</comment> ' . $difficulty . '/10 -> ' . $provider
            . ' / ' . config('assistant.providers.' . $provider . '.model'));
        $this->newLine();

        try {
            $answer = $loop->ask(
                user: $user,
                question: (string) $this->argument('question'),
                system: $this->systemPrompt(),
                context: $this->userContext($user),
                difficulty: $difficulty,
                /** Same call the box makes, or the command answers on a different model. */
                needs_vision: NeedsEyes::inQuestion($this->argument('question')),
                max_rounds: (int) $this->option('steps'),
                onText: fn (string $text) => $this->line($text),
                onTool: fn (string $name, array $arguments, bool $failed) => $this->line(
                    '  <comment>→ ' . $name . '</comment> '
                    . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ($failed ? ' <fg=red>mislukt</>' : '')
                ),
            );
        } catch (ModelUnavailable $e) {
            return $this->reportModelFailure($e);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<comment>' . $answer->tool_rounds . ' tool-ronde(s), '
            . $answer->inputTokens() . ' in / ' . $answer->outputTokens() . ' uit, '
            . '€' . number_format($answer->costEuros(), 4, ',', '') . '</comment>');

        $this->line('<comment>deze maand: €'
            . number_format(AssistantUsage::spentMicrosInMonth() / 1_000_000, 2, ',', '')
            . ' over ' . AssistantUsage::inMonth(now())->count() . ' aanroepen</comment>');

        return self::SUCCESS;
    }

    /**
     * Named in terms of what a person can do about it, not in terms of whose API
     * it was. Swapping supplier should not rewrite the error messages.
     */
    private function reportModelFailure(ModelUnavailable $e): int
    {
        $this->error(match ($e->reason) {
            ModelFailure::no_credit => 'Het AI-account heeft geen tegoed meer.',
            ModelFailure::bad_credentials => 'De API-sleutel wordt niet geaccepteerd.',
            ModelFailure::unreachable => 'Geen verbinding met de AI-dienst: ' . $e->getMessage(),
            ModelFailure::other => $e->getMessage(),
        });

        return self::FAILURE;
    }

    /**
     * Identical for everybody, every day, on purpose. This sits behind the cache
     * marker together with the tool definitions, and a cached prefix only pays
     * off while it stays byte-for-byte the same — so the name and the date live
     * in the message instead, where they cost nothing to vary.
     */
    private function systemPrompt(): string
    {
        return implode("\n", [
            'Je bent de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.',
            'Je schrijft altijd Nederlands, ook de zinnetjes tussendoor waarin je zegt wat je gaat opzoeken.',
            'Je antwoordt kort en concreet.',
            '',
            'Gebruik de tools om echte gegevens op te halen. Verzin nooit een werkbonnummer,',
            'klantnaam of datum: als je het niet uit een tool hebt, zeg je dat je het niet weet.',
            'Een tool geeft alleen terug wat deze gebruiker mag zien, dus een leeg resultaat',
            'betekent "niets gevonden of niets zichtbaar", niet "het bestaat niet".',
        ]);
    }

    private function userContext(User $user): string
    {
        return 'Je praat met ' . $user->name . '. Vandaag is ' . now()->toDateString() . '.';
    }

    private function resolveUser(): ?User
    {
        $id = $this->option('user');
        $user = $id ? User::find($id) : User::orderBy('id')->first();

        if (!$user) {
            $this->error($id ? 'Gebruiker ' . $id . ' bestaat niet.' : 'Er zijn geen gebruikers.');
        }

        return $user;
    }
}
