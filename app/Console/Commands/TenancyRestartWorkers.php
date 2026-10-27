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
 * So: restart the units, stop whatever of ours runs outside them, and then
 * wait until both queues report in with the code that is here now.
 */
class TenancyRestartWorkers extends Command
{
    protected $signature = 'tenancy:restart-workers {--timeout=45 : seconds to wait for a worker to report in}';

    protected $description = 'Restarts the queue workers and waits until they run the current code';

    public function handle(): int
    {
        if ($this->restartUnits()) {
            $this->stopStrays();
        } else {
            $this->call('queue:restart');
            $this->line('  Note: the workers were only signalled. Run scripts/tenancy/setup-sudoers.sh'
                . ' as root and the deploy may restart them itself.');
        }

        if ($this->reportingIn()) {
            return self::SUCCESS;
        }

        $this->warn('  The workers do not run the code that is checked out; see above.');

        return self::FAILURE;
    }

    private function restartUnits(): bool
    {
        exec('sudo -n systemctl restart ' . implode(' ', array_map('escapeshellarg', WorkerProcesses::UNITS))
            . ' 2>/dev/null', $output, $status);

        if ($status === 0) {
            $this->line('  workers restarted');
        }

        return $status === 0;
    }

    /** Waits until every queue reports in with the code that is checked out. */
    private function reportingIn(): bool
    {
        return $this->call('tenancy:await-workers', ['--timeout' => $this->option('timeout')]) === self::SUCCESS;
    }

    /**
     * Our workers outside the units: started by hand once, untouched by the
     * restart, and writing the old code into the heartbeat. Only after the
     * units did restart -- without them every worker is outside one, and
     * nothing would start them again.
     */
    private function stopStrays(): void
    {
        $strays = array_filter(WorkerProcesses::ours(), fn (array $worker) => !WorkerProcesses::inUnit($worker));

        if ($strays === []) {
            return;
        }

        $this->line('  Running next to the units, where a restart does not reach them:');

        foreach ($strays as $stray) {
            $this->line('    ' . WorkerProcesses::describe($stray)
                . (WorkerProcesses::stop($stray) ? ' -- stopped' : ' -- cannot be stopped from this account'));
        }
    }
}
