<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EntersATenant;
use App\Domain\Assistant\Evals\Euro;
use App\Domain\Assistant\Evals\EvalFiles;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\Evals\EvalSandbox;
use App\Domain\Assistant\Evals\EvalScenario;
use App\Domain\Assistant\Evals\EvalScenarios;
use App\Domain\Assistant\ModelPicker;
use Illuminate\Console\Command;

/**
 * Runs the assistant's scenarios end to end against a customer's real data, in
 * a transaction that is rolled back afterwards, and grades each run. Files the
 * run writes go to a folder of their own that is removed at the end.
 *
 * Every run costs real money on the API key — the scenario's own turns, the
 * simulated user and the judge — and prints what it cost.
 *
 * Results land in storage/assistant-evals/<moment>/: one JSON line per run and
 * a readable transcript beside it. They hold customer data and are not in git.
 * The command fails when any check failed, any run broke off or the judge
 * could not grade one. A criterion the judge failed does not: it is a reader's
 * opinion, and counted and printed at the end instead.
 */
class AssistantEvalCommand extends Command
{
    use EntersATenant;

    protected $signature = 'assistant:eval
        {--tenant=Demo : Id or name of the customer whose data the scenarios run against}
        {--suite= : Only scenarios of this suite (offerte, planning, prijzen)}
        {--scenario=* : Only these scenarios, by name}
        {--repeat=1 : How many times to run each scenario}
        {--provider= : Answer every question with this provider (one with a key, not one kept for a job of its own) instead of letting the sorter choose}
        {--no-judge : Skip the reading judge; only the counted checks}
        {--list : List the scenarios, with why any of them cannot run in this customer, and stop}';

    protected $description = 'Draait de testscenario\'s van de assistent en beoordeelt ze.';

    public function handle(): int
    {
        if (!$this->optionsMakeSense()) {
            return self::FAILURE;
        }

        $tenant = $this->enterTenant((string) $this->option('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        if ($refusal = EvalSandbox::refusal($tenant)) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $scenarios = $this->scenarios();

        if ($scenarios === null) {
            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listScenarios($scenarios);
        }

        $this->useProvider();
        $failed = EvalSandbox::around(
            $tenant,
            fake_disks: true,
            waiting: fn () => $this->line('<comment>Er loopt al een run op deze klant; wachten tot die klaar is…</comment>'),
            work: fn () => $this->runAll($scenarios, $this->resultsFolder()),
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Checked before anything is entered or spent: a --provider that cannot
     * answer would otherwise surface as a model failure halfway through the
     * first scenario, and a --repeat of 0 would run nothing and pass.
     */
    private function optionsMakeSense(): bool
    {
        $provider = (string) $this->option('provider');
        $answering = ModelPicker::answering();

        if ($provider !== '' && !in_array($provider, $answering, true)) {
            $this->error('--provider=' . $provider . ' kan hier geen vragen beantwoorden: '
                . match (true) {
                    config('assistant.providers.' . $provider) === null => 'die aanbieder bestaat niet',
                    (bool) config('assistant.providers.' . $provider . '.reserved') => 'die is gereserveerd voor een eigen taak',
                    default => 'die heeft geen API-sleutel',
                }
                . '. Kies uit: ' . ($answering === [] ? '(geen, er is geen enkele API-sleutel ingesteld)' : implode(', ', $answering)) . '.');

            return false;
        }

        if (filter_var($this->option('repeat'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('--repeat is een heel getal van 1 of meer.');

            return false;
        }

        return true;
    }

    /** @return array<int, EvalScenario>|null Null, said why, when the options pick nothing that exists. */
    private function scenarios(): ?array
    {
        $all = EvalScenarios::all();
        $suite = (string) $this->option('suite');
        $suites = array_values(array_unique(array_map(fn (EvalScenario $scenario) => $scenario->suite(), $all)));
        $names = (array) $this->option('scenario');
        $unknown = array_diff($names, array_map(fn (EvalScenario $scenario) => $scenario->name(), $all));

        if ($suite !== '' && !in_array($suite, $suites, true)) {
            $this->error('Onbekende suite "' . $suite . '". Kies uit ' . implode(', ', $suites) . '.');

            return null;
        }

        if ($unknown !== []) {
            $this->error('Onbekend scenario: ' . implode(', ', $unknown) . '. Zie --list.');

            return null;
        }

        $picked = array_values(array_filter($all, fn (EvalScenario $scenario) => ($suite === '' || $scenario->suite() === $suite)
            && ($names === [] || in_array($scenario->name(), $names, true))));

        if ($picked === []) {
            $this->error('Geen van de scenario\'s ' . implode(', ', $names) . ' hoort bij suite "' . $suite . '". Laat --suite weg, of zie --list.');

            return null;
        }

        return $picked;
    }

    private function listScenarios(array $scenarios): int
    {
        foreach ($scenarios as $scenario) {
            $this->line($scenario->name() . ' <info>(' . $scenario->role() . ')</info>  <comment>' . ($this->unavailable($scenario) ?? '') . '</comment>');
        }

        return self::SUCCESS;
    }

    private function useProvider(): void
    {
        if (filled($this->option('provider'))) {
            $model = app(ModelPicker::class)->forProvider((string) $this->option('provider'));
            app()->instance(ModelPicker::class, ModelPicker::fixed($model));
        }
    }

    /**
     * Why a scenario cannot run here: what the scenario itself says, or that the
     * provider forced with --provider cannot open the files it comes with. Left
     * to run, the offerte would be answered by a model that never saw it.
     */
    private function unavailable(EvalScenario $scenario): ?string
    {
        $reason = $scenario->unavailable();
        $provider = (string) $this->option('provider');

        if ($reason !== null || $provider === '') {
            return $reason;
        }

        $settings = (array) config('assistant.providers.' . $provider, []);

        foreach ($scenario->files() as $file) {
            if ($file->isPicture() && !($settings['sees_images'] ?? false)) {
                return $provider . ' kan geen foto\'s bekijken, en dit scenario stuurt ' . $file->name . ' mee';
            }

            if (!$file->isPicture() && !($settings['reads_documents'] ?? false)) {
                return $provider . ' kan geen bestanden lezen, en dit scenario stuurt ' . $file->name . ' mee';
            }
        }

        return null;
    }

    private function resultsFolder(): string
    {
        return EvalFiles::folder(EvalFiles::root() . '/' . EvalFiles::moment()
            . ($this->option('provider') ? '_' . $this->option('provider') : ''));
    }

    /** @return bool Whether any run failed a check or broke off. */
    private function runAll(array $scenarios, string $directory): bool
    {
        $runner = new EvalRunner;
        $rows = [];
        $spent = 0;
        $failed = false;
        $judged = ['runs' => 0, 'criteria' => 0, 'failed' => 0];

        foreach ($scenarios as $scenario) {
            if ($reason = $this->unavailable($scenario)) {
                $this->warn($scenario->name() . ' overgeslagen: ' . $reason);

                continue;
            }

            for ($attempt = 1; $attempt <= (int) $this->option('repeat'); $attempt++) {
                $this->line('<comment>▶ ' . $scenario->name() . ' #' . $attempt . '</comment>');
                $result = $runner->run($scenario, judge: !$this->option('no-judge'));
                $this->keep($directory, $result, $attempt);
                $rows[] = $this->show($result, $attempt);
                $spent += $result['cost_micros'] + $result['helper_cost_micros'];
                $failed = $failed || $result['failed'];
                $criteria = $result['verdict']['criteria'] ?? [];
                $judged['runs'] += $criteria === [] ? 0 : 1;
                $judged['criteria'] += count($criteria);
                $judged['failed'] += count(array_filter($criteria, fn ($row) => !$row['pass']));
            }
        }

        $this->newLine();
        $this->table(['scenario', 'checks', 'oordeel', 'kosten'], $rows);

        if ($judged['runs'] > 0) {
            $this->line(($judged['failed'] > 0 ? '<comment>' : '<info>') . 'Oordeel: ' . $judged['failed'] . ' van de ' . $judged['criteria']
                . ' criteria afgekeurd, over ' . $judged['runs'] . ' beoordeelde run(s).' . ($judged['failed'] > 0 ? '</comment>' : '</info>')
                . ' Afgekeurde criteria laten het commando niet falen; checks, afgebroken runs en een mislukt oordeel wel.');
        }

        $this->line('Totaal ' . Euro::micros($spent, 2) . '. Transcripten: ' . $directory);

        return $failed;
    }

    private function keep(string $directory, array $result, int $attempt): void
    {
        EvalFiles::write($directory . '/' . $result['scenario'] . '_' . $attempt . '.md', $this->report($result));
        EvalFiles::write($directory . '/results.jsonl', json_encode(
            [...$result, 'attempt' => $attempt, 'transcript' => $result['transcript']->toArray()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n", FILE_APPEND);
    }

    /** Prints one run and gives back its row for the table at the end. */
    private function show(array $result, int $attempt): array
    {
        $transcript = $result['transcript'];

        foreach ($result['checks'] as $check) {
            if (!$check['passed']) {
                $this->line('   <fg=red>✗</> ' . $check['name'] . ($check['detail'] !== '' ? ' — ' . mb_substr($check['detail'], 0, 160) : ''));
            }
        }

        $judged = $result['verdict']['criteria'] ?? [];
        $judged_passed = count(array_filter($judged, fn ($row) => $row['pass']));
        $judgement = $judged !== [] ? $judged_passed . '/' . count($judged) : '-';

        foreach ($judged as $row) {
            if (!$row['pass']) {
                $this->line('   <fg=yellow>~</> oordeel: ' . $row['criterion'] . ($row['note'] !== '' ? ' — ' . mb_substr($row['note'], 0, 160) : ''));
            }
        }

        if ($result['verdict']['failed'] ?? false) {
            $this->line('   <fg=red>✗</> ' . $result['verdict']['worst_problem']);
        }

        $this->line('   checks ' . $result['passed'] . '/' . $result['total'] . ', oordeel ' . $judgement
            . ', ' . count($transcript->turns) . ' beurten, ' . $transcript->repeatedLookups() . 'x herhaald opgezocht, '
            . Euro::micros($result['cost_micros']) . ' (+' . Euro::micros($result['helper_cost_micros']) . ' eval)'
            . ' — ' . $transcript->endingLine());

        return [$result['scenario'] . ' #' . $attempt, $result['passed'] . '/' . $result['total'], $judgement, Euro::micros($result['cost_micros'] + $result['helper_cost_micros'])];
    }

    private function report(array $result): string
    {
        $lines = ['# ' . $result['scenario'], '', '## Checks', ''];

        foreach ($result['checks'] as $check) {
            $lines[] = '- ' . ($check['passed'] ? '✓' : '✗') . ' ' . $check['name'] . ($check['detail'] !== '' ? ' — ' . $check['detail'] : '');
        }

        if ($result['verdict'] !== null) {
            $lines[] = '';
            $lines[] = '## Oordeel';
            $lines[] = '';

            foreach ($result['verdict']['criteria'] as $row) {
                $lines[] = '- ' . ($row['pass'] ? '✓' : '✗') . ' ' . $row['criterion'] . ($row['note'] !== '' ? ' — ' . $row['note'] : '');
            }

            $lines[] = '';
            $lines[] = 'Ergste probleem: ' . $result['verdict']['worst_problem'];
        }

        $lines[] = '';
        $lines[] = '## Gesprek';
        $lines[] = '';
        $lines[] = $result['transcript']->markdown();

        return implode("\n", $lines);
    }
}
