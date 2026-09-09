<?php

namespace App\Console\Commands;

use App\Support\WorkerHeartbeat;
use Illuminate\Console\Command;

/**
 * Waits until the workers run the code that is checked out.
 *
 * systemctl restart returns as soon as the unit is up, not as soon as php has
 * finished booting. The check that came right after it therefore still saw the
 * previous worker's fingerprint and reported both workers on old code at every
 * deploy -- while they had just been restarted. A finding that always shows up
 * is a finding nobody reads.
 */
class TenancyAwaitWorkers extends Command
{
    protected $signature = 'tenancy:await-workers
        {--timeout=60 : hoeveel seconden er hoogstens gewacht wordt}
        {--queues=default,provisioning : welke wachtrijen}';

    protected $description = 'Wacht tot elke worker zich meldt met de code die er nu staat';

    public function handle(): int
    {
        $queues = array_filter(array_map('trim', explode(',', (string) $this->option('queues'))));
        $deadline = time() + max(1, (int) $this->option('timeout'));

        $waiting = $queues;

        while ($waiting !== []) {
            $waiting = array_values(array_filter($waiting, fn (string $queue) => !$this->isCurrent($queue)));

            if ($waiting === []) {
                break;
            }

            if (time() >= $deadline) {
                $this->warn('  Nog niet gemeld: ' . implode(', ', $waiting));

                $code = WorkerHeartbeat::codeVersion();

                $this->line('  hier staat: ' . base_path() . ', code '
                    . ($code === '' ? 'onbekend' : substr($code, 0, 8)));

                foreach ($waiting as $queue) {
                    foreach (WorkerHeartbeat::reporterLines($queue) as $line) {
                        $this->line("  {$queue}: {$line}");
                    }
                }

                return self::FAILURE;
            }

            sleep(1);
        }

        $this->line('  workers draaien op de nieuwe code');

        return self::SUCCESS;
    }

    /**
     * The same question the doctor asks: a fresh heartbeat, and the code and
     * settings the worker booted with equal to what is here now.
     */
    private function isCurrent(string $queue): bool
    {
        $beat = WorkerHeartbeat::beatFor($queue);

        if ($beat === null || now()->timestamp - $beat > WorkerHeartbeat::STALE_AFTER_MINUTES * 60) {
            return false;
        }

        $code = WorkerHeartbeat::codeFor($queue);
        $now = WorkerHeartbeat::codeVersion();

        if ($code !== null && $code !== '' && $now !== '' && $code !== $now) {
            return false;
        }

        $settings = WorkerHeartbeat::settingsFor($queue);

        return $settings === null || $settings === WorkerHeartbeat::settingsFingerprint();
    }
}
