<?php

namespace App\Console\Commands;

use App\Support\WorkerProcesses;
use Illuminate\Console\Command;

/**
 * Puts the queue workers on the code that is checked out, and proves it.
 *
 * Php holds on to everything it read at boot, so after a deploy a worker keeps
 * running the previous version. `systemctl restart` only touches what the unit
 * itself started: a worker that was once started by hand keeps running, writes
 * into the same heartbeat, and keeps the "running older code" finding alive no
 * matter how often you restart. Two deploys in a row showed exactly that.
 *
 * So: restart the units, stop whatever survived that restart, and then wait
 * until both queues report in with the code that is here now.
 */
class TenancyRestartWorkers extends Command
{
    protected $signature = 'tenancy:restart-workers {--timeout=45 : seconds to wait for a worker to report in}';

    protected $description = 'Restarts the queue workers and waits until they run the current code';

    /** @var array<int, string> */
    private const UNITS = ['lavoro-worker', 'lavoro-provisioning'];

    /** A process older than this survived the restart, so the unit does not own it. */
    private const SURVIVED_AFTER_SECONDS = 15;

    public function handle(): int
    {
        if (!$this->restartUnits()) {
            $this->call('queue:restart');
            $this->line('  Let op: workers alleen een sein gegeven. Draai scripts/tenancy/setup-sudoers.sh'
                . ' als root, dan mag de uitrol ze zelf herstarten.');
        }

        if ($this->reportingIn()) {
            return self::SUCCESS;
        }

        if ($this->stopStrays()) {
            $this->restartUnits();

            if ($this->reportingIn()) {
                return self::SUCCESS;
            }
        }

        $this->warn('  De workers draaien niet op de code die er nu staat; zie hierboven.');

        return self::FAILURE;
    }

    private function restartUnits(): bool
    {
        exec('sudo -n systemctl restart ' . implode(' ', array_map('escapeshellarg', self::UNITS))
            . ' 2>/dev/null', $output, $status);

        if ($status === 0) {
            $this->line('  workers herstart');
        }

        return $status === 0;
    }

    /** Waits until every queue reports in with the code that is checked out. */
    private function reportingIn(): bool
    {
        return $this->call('tenancy:await-workers', ['--timeout' => $this->option('timeout')]) === self::SUCCESS;
    }

    /** Whether anything was stopped, and therefore worth restarting for. */
    private function stopStrays(): bool
    {
        $strays = array_filter(WorkerProcesses::all(),
            fn (array $worker) => $worker['seconds'] > self::SURVIVED_AFTER_SECONDS);

        if ($strays === []) {
            return false;
        }

        $this->line('  Deze draaiden al voor de herstart en horen dus niet bij de units:');

        $stopped = false;

        foreach ($strays as $stray) {
            $went = WorkerProcesses::stop($stray);
            $stopped = $stopped || $went;

            $this->line('    ' . WorkerProcesses::describe($stray)
                . ($went ? ' -- omgelegd' : ' -- niet te stoppen vanaf dit account'));
        }

        return $stopped;
    }
}
