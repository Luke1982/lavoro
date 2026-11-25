<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EntersATenant;
use App\Domain\Assistant\Evals\Euro;
use App\Domain\Assistant\Evals\EvalFiles;
use App\Domain\Assistant\Evals\EvalModel;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\Evals\EvalSandbox;
use App\Domain\Assistant\Evals\EvalTranscript;
use App\Domain\Assistant\Evals\EvalUsers;
use App\Domain\Assistant\Evals\ProbeQuestions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks the assistant a broad set of everyday questions once each and sorts the
 * answers: answered from the data, asked back, honestly could not, or made
 * something up. What it could not do, grouped, is the list of what to build next.
 *
 * Each question is asked as somebody in the role it belongs to, in a transaction
 * that is rolled back; nothing is confirmed, so nothing is carried out. Every
 * question costs real money on the API key; the total is printed. The command
 * fails when a question broke off or the sorting did.
 */
class AssistantProbeCommand extends Command
{
    use EntersATenant;

    protected $signature = 'assistant:probe
        {--tenant=Demo : Id or name of the customer whose data is used}
        {--group=* : Only these groups of questions (planner, monteur, kantoor, beheer)}
        {--limit=0 : Stop after this many questions}';

    protected $description = 'Stelt de assistent een brede reeks vragen en zoekt uit waar hij tekortschiet.';

    private const SORTER = <<<'TXT'
Je beoordeelt antwoorden van de assistent van Lavoro, een systeem voor installatie- en servicebedrijven.
Per vraag zie je welke tools er werden aangeroepen (met resultaat) en het antwoord.
Een heel lang toolresultaat is ingekort; waar dat zo is, staat "[ingekort: ...]". Wat in het weggelaten deel kan hebben gestaan, reken je niet als verzonnen.
Deel elk antwoord in als precies één van:
- "beantwoord": geeft een echt antwoord uit de gegevens of zet de gevraagde actie klaar.
- "doorgevraagd": vraagt terecht om ontbrekende informatie.
- "kan_niet": zegt eerlijk dat hij dit niet kan.
- "half": doet een deel, of geeft een algemeen antwoord waar de vraag om gegevens vroeg.
- "verzonnen": noemt namen, nummers, data of feiten die niet uit een toolresultaat komen, of doet alsof iets gelukt is.
Geef bij alles behalve "beantwoord" en "doorgevraagd" in één zin wat de assistent zou moeten kunnen om de vraag goed te beantwoorden ("capability"), zo concreet mogelijk (bv. "afspraken verzetten", "materiaal op een werkbon boeken").

Antwoord met alleen een JSON-object:
{"results": [{"index": 0, "class": "...", "capability": "...", "note": "korte toelichting"}]}
TXT;

    private const CHUNK = 10;

    /** What the sorter may answer; anything else it says is shown as "?". */
    private const CLASSES = ['beantwoord', 'doorgevraagd', 'kan_niet', 'half', 'verzonnen'];

    private const GAPS = ['kan_niet', 'half', 'verzonnen'];

    private bool $failed = false;

    public function handle(): int
    {
        $tenant = $this->enterTenant((string) $this->option('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        if ($refusal = EvalSandbox::refusal($tenant)) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $groups = (array) $this->option('group');
        $unknown = array_diff($groups, array_keys(ProbeQuestions::all()));

        if ($unknown !== []) {
            $this->error('Onbekende groep: ' . implode(', ', $unknown) . '. Kies uit ' . implode(', ', array_keys(ProbeQuestions::all())) . '.');

            return self::FAILURE;
        }

        /** The sorting reads nothing of the customer's, so the lock is let go before it starts. */
        $asked = EvalSandbox::around(
            $tenant,
            fake_disks: false,
            waiting: fn () => $this->line('<comment>Er loopt al een run op deze klant; wachten tot die klaar is…</comment>'),
            work: fn () => $this->askEveryQuestion($groups),
        );

        $sorter = new EvalModel('eval_judge');
        $rows = $this->sort($sorter, $asked);

        $spent = array_sum(array_column($rows, 'cost_micros')) + $sorter->cost_micros;
        $file = EvalFiles::write(EvalFiles::folder(EvalFiles::root()) . '/probe_' . EvalFiles::moment() . '.md', $this->report($rows));

        $counts = array_count_values(array_map(fn ($row) => $row['verdict']['class'] ?? '?', $rows));
        arsort($counts);
        $this->newLine();
        $this->table(['indeling', 'aantal'], array_map(fn ($class, $count) => [$class, $count], array_keys($counts), $counts));
        $this->line('Totaal ' . Euro::micros($spent, 2) . '. Rapport: ' . $file);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every question once, as somebody in the role of its group.
     *
     * @param  array<int, string>  $groups  Only these, or every group when empty.
     */
    private function askEveryQuestion(array $groups): array
    {
        $runner = new EvalRunner;
        $limit = (int) $this->option('limit');
        $rows = [];

        foreach (ProbeQuestions::all() as $group => $questions) {
            if ($groups !== [] && !in_array($group, $groups, true)) {
                continue;
            }

            $user = EvalUsers::forRole(ProbeQuestions::roleOf($group));

            if ($user === null) {
                $this->warn($group . ' overgeslagen: geen gebruiker met de rol ' . ProbeQuestions::roleOf($group) . '.');

                continue;
            }

            foreach ($questions as $question) {
                if ($limit > 0 && count($rows) >= $limit) {
                    return $rows;
                }

                $turn = EvalSandbox::rolledBack(fn () => $runner->turn('ask', $user, $question));
                $rows[] = [...$turn, 'group' => $group, 'user' => $user->name];
                $this->failed = $this->failed || $turn['error'] !== null;
                $this->line('<comment>' . $group . '</comment> ' . $question . ' → '
                    . ($turn['error'] !== null ? '<fg=red>' . $turn['error'] . '</>' : implode(', ', array_column($turn['tools'], 'name'))));
            }
        }

        return $rows;
    }

    /**
     * Has the judge's model sort the answers, a chunk at a time. An answer it
     * left out counts as a failed sorting, not as an unsorted "?" that a green
     * run would hide.
     */
    private function sort(EvalModel $sorter, array $rows): array
    {
        foreach (array_chunk($rows, self::CHUNK, true) as $chunk) {
            try {
                foreach ((array) ($sorter->json(self::SORTER, $this->sortingPrompt($chunk))['results'] ?? []) as $verdict) {
                    $index = is_array($verdict) ? ($verdict['index'] ?? null) : null;

                    if (is_int($index) && array_key_exists($index, $chunk)) {
                        $rows[$index]['verdict'] = self::verdict($verdict);
                    }
                }
            } catch (Throwable $e) {
                $this->failed = true;
                $this->warn('Indelen mislukt: ' . $e->getMessage());

                continue;
            }

            $unsorted = array_keys(array_filter($chunk, fn ($row, $index) => !isset($rows[$index]['verdict']), ARRAY_FILTER_USE_BOTH));

            if ($unsorted !== []) {
                $this->failed = true;
                $this->warn('Niet ingedeeld: vraag ' . implode(', ', $unsorted) . '.');
            }
        }

        return $rows;
    }

    /**
     * The sorter's verdict as plain text in known places. It is a model's
     * answer, read after the money is spent: a class it made up, or a list where
     * a sentence belongs, must not be what stops the report being written.
     *
     * @return array{class: string, capability: string, note: string}
     */
    private static function verdict(array $verdict): array
    {
        $text = fn (mixed $value) => is_scalar($value) ? trim((string) $value) : '';
        $class = $text($verdict['class'] ?? null);

        return [
            'class' => in_array($class, self::CLASSES, true) ? $class : '?',
            'capability' => $text($verdict['capability'] ?? null) ?: '?',
            'note' => $text($verdict['note'] ?? null),
        ];
    }

    private function sortingPrompt(array $chunk): string
    {
        $lines = [];

        foreach ($chunk as $index => $row) {
            $lines[] = '## ' . $index . ' (' . $row['group'] . ', als ' . $row['user'] . ')';
            $lines[] = 'Vraag: ' . $row['question'];

            foreach ($row['tools'] as $tool) {
                $lines[] = '- tool ' . $tool['name'] . ' ' . EvalTranscript::text($tool['arguments']) . ' → ' . EvalTranscript::clip(EvalTranscript::text($tool['content']));
            }

            $lines[] = 'Antwoord: ' . ($row['error'] ?? $row['answer']);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function report(array $rows): string
    {
        $lines = ['# Wat de assistent niet kan', ''];
        $gaps = [];

        foreach ($rows as $row) {
            $class = $row['verdict']['class'] ?? '?';

            if (in_array($class, self::GAPS, true)) {
                $gaps[$row['verdict']['capability'] ?? '?'][] = $row;
            }
        }

        uasort($gaps, fn ($a, $b) => count($b) <=> count($a));
        $lines[] = '## Ontbrekend, gegroepeerd';
        $lines[] = '';

        foreach ($gaps as $capability => $examples) {
            $lines[] = '- **' . $capability . '** (' . count($examples) . '): ' . implode(' / ', array_map(fn ($row) => '"' . $row['question'] . '" [' . ($row['verdict']['class'] ?? '?') . ']', $examples));
        }

        $lines[] = '';
        $lines[] = '## Per vraag';

        foreach ($rows as $row) {
            $lines[] = '';
            $lines[] = '### [' . ($row['verdict']['class'] ?? '?') . '] ' . $row['question'] . ' (' . $row['group'] . ', ' . $row['user'] . ', ' . $row['model'] . ')';
            $lines[] = 'Tools: ' . (implode(', ', array_column($row['tools'], 'name')) ?: 'geen');
            $lines[] = '';
            $lines[] = $row['error'] !== null ? '**FOUT:** ' . $row['error'] : $row['answer'];

            if (filled($row['verdict']['note'] ?? null)) {
                $lines[] = '';
                $lines[] = '_' . $row['verdict']['note'] . '_';
            }
        }

        return implode("\n", $lines);
    }
}
