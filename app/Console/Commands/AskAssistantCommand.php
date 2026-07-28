<?php

namespace App\Console\Commands;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Domain\Assistant\AssistantLoop;
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
        {--show-results : Print what each tool returned, not just what was asked}';

    protected $description = 'Stelt de assistent één vraag vanaf de commandline.';

    public function handle(AssistantLoop $loop, ToolRegistry $registry): int
    {
        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        if (blank(config('assistant.api_key'))) {
            $this->error('ANTHROPIC_API_KEY ontbreekt in .env.');

            return self::FAILURE;
        }

        $this->line('<comment>Als:</comment> ' . $user->name . ' (' . ToolProfile::forUser($user)->value
            . ', ' . count($registry->definitionsFor($user)) . ' tools)');
        $this->newLine();

        try {
            $answer = $loop->ask(
                user: $user,
                question: (string) $this->argument('question'),
                system: $this->systemPrompt($user),
                max_rounds: (int) $this->option('steps'),
                onText: fn (string $text) => $this->line($text),
                onTool: fn (string $name, array $arguments, bool $failed) => $this->line(
                    '  <comment>→ ' . $name . '</comment> '
                    . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ($failed ? ' <fg=red>mislukt</>' : '')
                ),
            );
        } catch (APIStatusException $e) {
            return $this->reportApiFailure($e);
        } catch (APIConnectionException $e) {
            $this->error('Geen verbinding met de Anthropic API: ' . $e->getMessage());

            return self::FAILURE;
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
     * The two failures worth naming are the ones a person can act on. Everything
     * else is shown as it came back, because guessing at it would hide it.
     */
    private function reportApiFailure(APIStatusException $e): int
    {
        $message = $e->getMessage();

        if (str_contains($message, 'credit balance')) {
            $this->error('Het Anthropic-account heeft geen tegoed. Vul het aan onder Plans & Billing.');

            return self::FAILURE;
        }

        if ($e->status === 401) {
            $this->error('ANTHROPIC_API_KEY wordt niet geaccepteerd.');

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * The date has to be in here because "deze week" is unanswerable without it.
     * In the chat version it belongs after the cached part of the prompt rather
     * than in front of it, or every new day starts from a cold cache.
     */
    private function systemPrompt(User $user): string
    {
        return implode("\n", [
            'Je bent de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.',
            'Je schrijft altijd Nederlands, ook de zinnetjes tussendoor waarin je zegt wat je gaat opzoeken.',
            'Je antwoordt kort en concreet.',
            '',
            'Je praat met ' . $user->name . '. Vandaag is ' . now()->toDateString() . '.',
            '',
            'Gebruik de tools om echte gegevens op te halen. Verzin nooit een werkbonnummer,',
            'klantnaam of datum: als je het niet uit een tool hebt, zeg je dat je het niet weet.',
            'Een tool geeft alleen terug wat deze gebruiker mag zien, dus een leeg resultaat',
            'betekent "niets gevonden of niets zichtbaar", niet "het bestaat niet".',
        ]);
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
