<?php

namespace App\Console\Commands;

use App\Support\WorkerHeartbeat;
use Illuminate\Console\Command;

/**
 * Wacht tot de workers weer op de nieuwe code draaien.
 *
 * systemctl restart komt terug zodra de unit is aangezet, niet zodra php klaar
 * is met opstarten. De controle die daar meteen achteraan kwam zag daardoor nog
 * de vingerafdruk van de vorige worker en meldde bij elke uitrol dat beide
 * workers op oude code draaiden -- terwijl ze net herstart waren. Een melding
 * die altijd verschijnt is een melding die niemand meer leest.
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
                $this->warn('  Nog niet gemeld: ' . implode(', ', $waiting)
                    . '. De controle hieronder zegt waarom.');

                return self::FAILURE;
            }

            sleep(1);
        }

        $this->line('  workers draaien op de nieuwe code');

        return self::SUCCESS;
    }

    /**
     * Dezelfde vraag als de doctor stelt: een verse hartslag, en de code en
     * instellingen waarmee de worker startte gelijk aan wat er nu staat.
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
