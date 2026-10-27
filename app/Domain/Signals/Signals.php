<?php

namespace App\Domain\Signals;

use App\Support\ForgetsTenantState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one door every signal goes through.
 *
 * A listener is allowed to cause further signals, which is the point of the
 * layer, but that also means a cascade can bite its own tail: a listener writes
 * to a record, the record announces the change, another listener reacts by
 * writing again. Nothing in Laravel stops that, so this does.
 *
 * Three limits, each of which reports loudly rather than failing silently:
 *
 *   - the same fact about the same record cannot occur twice in one chain,
 *     which is what a loop looks like from the inside;
 *   - a chain cannot run deeper than MAX_DEPTH, which catches a cycle whose
 *     participants differ each time round;
 *   - one request cannot raise more than MAX_PER_REQUEST signals, which stops a
 *     runaway fan-out from taking the process down.
 *
 * Every signal in one chain also shares a correlation id, so the whole cascade
 * from a single user action can be read back as one story.
 */
class Signals implements ForgetsTenantState
{
    public const MAX_DEPTH = 10;

    public const MAX_PER_REQUEST = 1000;

    /**
     * Outside a web request the unit of work is a whole command or a whole job,
     * and that is a different order of magnitude: rebuilding the demo customer
     * creates a company with everything in it, and every record it writes
     * announces itself. Against the web limit that run hit the ceiling within
     * seconds, and everything after it silently got no history at all -- while
     * writing one error line per skipped signal, thousands of them, into the
     * log.
     *
     * Loops are still caught: that is what the chain and MAX_DEPTH are for.
     * This number is only a backstop against something running away.
     */
    public const MAX_PER_CONSOLE_RUN = 250_000;

    /** @var array<int, string> The chain currently being dispatched. */
    private array $chain = [];

    private int $raised = 0;

    /** The ceiling is reported once, not once per signal it then skips. */
    private bool $ceiling_reported = false;

    private ?string $correlation_id = null;

    public static function dispatch(Signal $signal): void
    {
        app(self::class)->raise($signal);
    }

    public function raise(Signal $signal): void
    {
        $fingerprint = $this->fingerprint($signal);

        if (in_array($fingerprint, $this->chain, true)) {
            $this->report('Signaallus onderbroken', $fingerprint);

            return;
        }

        if (count($this->chain) >= self::MAX_DEPTH) {
            $this->report('Signaalketen te diep, afgebroken', $fingerprint);

            return;
        }

        if ($this->raised >= $this->ceiling()) {
            if (!$this->ceiling_reported) {
                $this->ceiling_reported = true;
                $this->report('Te veel signalen in één eenheid werk, de rest wordt overgeslagen', $fingerprint);
            }

            return;
        }

        $this->correlation_id ??= (string) Str::uuid();
        $signal->correlateWith($this->correlation_id);

        $this->raised++;
        $this->chain[] = $fingerprint;

        try {
            event($signal);
        } finally {
            array_pop($this->chain);

            if ($this->chain === []) {
                $this->correlation_id = null;
            }
        }
    }

    /** Configurable so a test does not have to raise a quarter of a million signals. */
    private function ceiling(): int
    {
        return app()->runningInConsole()
            ? (int) config('signals.max_per_console_run', self::MAX_PER_CONSOLE_RUN)
            : (int) config('signals.max_per_request', self::MAX_PER_REQUEST);
    }

    /**
     * A new unit of work: a queued job that is about to run, or a customer that
     * is being switched to. Without this a worker that stays up for days counts
     * every signal of every job it ever ran against one ceiling.
     */
    public function startFresh(): void
    {
        $this->chain = [];
        $this->raised = 0;
        $this->correlation_id = null;
        $this->ceiling_reported = false;
    }

    public function forgetTenantState(): void
    {
        $this->startFresh();
    }

    public function currentCorrelationId(): ?string
    {
        return $this->correlation_id;
    }

    /** @return array<int, string> */
    public function currentChain(): array
    {
        return $this->chain;
    }

    private function fingerprint(Signal $signal): string
    {
        $subject = $signal->subject();

        return $signal->eventKey() . '|' . $subject->getMorphClass() . '|' . $subject->getKey();
    }

    private function report(string $problem, string $fingerprint): void
    {
        Log::error($problem, [
            'signal' => $fingerprint,
            'chain' => $this->chain,
            'raised_in_this_unit_of_work' => $this->raised,
            'ceiling' => $this->ceiling(),
        ]);
    }
}
