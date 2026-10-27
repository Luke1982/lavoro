<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Signals\Signals;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The ceiling on signals is a backstop against something running away, and it
 * counts per unit of work. What a unit is differs: a web request is small, a
 * command or a queued job is not.
 *
 * Rebuilding the demo customer writes a whole company, and every record
 * announces itself. Against the web ceiling of a thousand that run was over the
 * limit within seconds, and from that moment on nothing got an activity entry
 * any more -- while writing one error line per skipped signal into the log,
 * thousands of them a night:
 *
 *   production.ERROR: Te veel signalen in één verzoek, afgebroken
 *   {"signal":"serviceorder.created|App\Models\ServiceOrder|44","raised_this_request":1000}
 */
class SignalsKeepCountingPerUnitOfWorkTest extends TestCase
{
    private function signals(): Signals
    {
        return app(Signals::class);
    }

    /** Each one raises a signal of its own through RecordsHistory. */
    private function makeSomeRecords(int $how_many): void
    {
        foreach (range(1, $how_many) as $number) {
            Customer::factory()->create();
        }
    }

    /** What the signals are for: the entries a timeline and the assistant read. */
    private function historyEntries(): int
    {
        return DB::connection('tenant')->table('activities')
            ->where('event_key', 'customer.created')->count();
    }

    public function test_a_command_may_raise_more_than_a_web_request(): void
    {
        config(['signals.max_per_request' => 2, 'signals.max_per_console_run' => 50]);

        /** The suite runs in the console, which is what a nightly rebuild is too. */
        $this->signals()->startFresh();

        $before = $this->historyEntries();

        $this->makeSomeRecords(6);

        $this->assertSame($before + 6, $this->historyEntries(),
            'all six were announced; against the web ceiling only two would have been');
    }

    public function test_the_ceiling_is_reported_once_and_not_per_signal(): void
    {
        config(['signals.max_per_console_run' => 3]);

        $this->signals()->startFresh();

        Log::spy();

        $this->makeSomeRecords(8);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'Te veel signalen'))
            ->once();
    }

    /**
     * A worker stays up for days. Every job it picks up calls startFresh()
     * (AppServiceProvider listens for JobProcessing); without that, the signals
     * of everything it has ever run are weighed against one ceiling, and at
     * some point a job gets no history for no reason it can see.
     *
     * The event itself is not fired here: stancl's own listener for it ends
     * tenancy, and in this suite that takes the test transaction with it.
     */
    public function test_a_fresh_start_lets_the_count_begin_again(): void
    {
        config(['signals.max_per_console_run' => 4]);

        $this->signals()->startFresh();
        $this->makeSomeRecords(4);

        $at_the_ceiling = $this->historyEntries();

        $this->makeSomeRecords(1);

        $this->assertSame($at_the_ceiling, $this->historyEntries(),
            'at the ceiling nothing is announced any more');

        /** What the next job does. */
        $this->signals()->startFresh();

        $this->makeSomeRecords(2);

        $this->assertSame($at_the_ceiling + 2, $this->historyEntries(),
            'the ceiling of the previous job must not be held against this one');
    }
}
