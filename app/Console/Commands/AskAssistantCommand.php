<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EntersATenant;
use App\Domain\Assistant\AssistantSession;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
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
    use EntersATenant;

    protected $signature = 'assistant:ask
        {question : The question, in Dutch}
        {--tenant= : Id or name of the customer to ask it in}
        {--user= : Id of the user to act as}
        {--steps=6 : How many tool rounds to allow before giving up}
        {--show-results : Print what each tool returned, not just what was asked}
        {--difficulty= : Force a difficulty from 1 to 10; empty or 0 lets the sorter decide, as in the box}';

    protected $description = 'Stelt de assistent één vraag vanaf de commandline.';

    public function handle(AssistantSession $session, ToolRegistry $registry): int
    {
        /**
         * Said before anything else, in so many words. Without it the question
         * goes to a model that cannot be reached, and comes back as whatever
         * that supplier's library says about a missing key.
         */
        if (ModelPicker::answering() === []) {
            $this->error('Er is geen API-sleutel ingesteld voor een aanbieder die vragen kan beantwoorden (bijvoorbeeld ANTHROPIC_API_KEY; zie config/assistant.php).');

            return self::FAILURE;
        }

        if (!$this->inACustomer()) {
            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        $difficulty = $this->difficulty();

        if ($difficulty === false) {
            $this->error('--difficulty is een getal van 1 tot 10, of 0 om de sortering te laten kiezen.');

            return self::FAILURE;
        }

        $this->line('<comment>Als:</comment> ' . $user->name . ' (' . ToolProfile::forUser($user)->value
            . ', ' . count($registry->definitionsFor($user)) . ' tools)');
        $this->newLine();

        $watcher = $session->watcher($user, null)->listen(function (string $name, array $arguments, bool $failed, ?ToolResult $result) {
            $this->line('  <comment>→ ' . $name . '</comment> '
                . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ($failed ? ' <fg=red>mislukt</>' : ''));

            if ($this->option('show-results') && $result !== null) {
                $this->line('    ' . mb_substr($result->toModelContent(), 0, 2000));
            }
        });

        try {
            $answer = $session->ask(
                user: $user,
                question: (string) $this->argument('question'),
                watcher: $watcher,
                difficulty: $difficulty,
                max_rounds: (int) $this->option('steps'),
                on_text: fn (string $text) => $this->line($text),
            );
        } catch (ModelUnavailable $e) {
            return $this->reportModelFailure($e);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<comment>moeilijkheid ' . ($answer->difficulty ?? '?') . '/10' . ($difficulty === null ? ' (gesorteerd)' : ' (opgegeven)')
            . ' -> ' . $answer->final->billableModel() . ', ' . $answer->tool_rounds . ' tool-ronde(s), '
            . $answer->inputTokens() . ' in / ' . $answer->outputTokens() . ' uit, '
            . '€' . number_format($answer->costEuros(), 4, ',', '') . '</comment>');

        $this->line('<comment>deze maand: €'
            . number_format(AssistantUsage::spentMicrosInMonth() / 1_000_000, 2, ',', '')
            . ' over ' . AssistantUsage::inMonth(now())->count() . ' aanroepen</comment>');

        return self::SUCCESS;
    }

    /**
     * The difficulty asked for, null to let the sorter decide as it does in the
     * box, or false when the option is not a difficulty at all.
     */
    private function difficulty(): int|false|null
    {
        $given = trim((string) $this->option('difficulty'));

        if ($given === '' || $given === '0') {
            return null;
        }

        $difficulty = filter_var($given, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);

        return $difficulty === false ? false : $difficulty;
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
     * The assistant reads one customer's data, so it needs to know whose. On
     * an installation with customers there is no default: asking the question
     * in the wrong company is worse than not asking it.
     */
    private function inACustomer(): bool
    {
        $wanted = (string) $this->option('tenant');

        if ($wanted === '') {
            if (tenancy()->initialized) {
                return true;
            }

            $this->error('Geen klant gekozen. Geef --tenant=<id of naam> mee; php artisan tenants:list toont ze.');

            return false;
        }

        return $this->enterTenant($wanted) !== null;
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
