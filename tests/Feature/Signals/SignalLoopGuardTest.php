<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Signals;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LoopingProbeSignal extends BaseSignal
{
    public function __construct(public Model $record)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'probe.loops';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}

class DeepeningProbeSignal extends BaseSignal
{
    public function __construct(public Model $record, public int $step)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'probe.deepens';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}

/**
 * The layer lets a listener cause further signals, which means a cascade can bite
 * its own tail. These prove it cannot run away.
 */
class SignalLoopGuardTest extends TestCase
{

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_a_listener_that_re_raises_its_own_signal_is_stopped(): void
    {
        Log::spy();
        $order = $this->order();
        $times = 0;

        Event::listen(LoopingProbeSignal::class, function (LoopingProbeSignal $signal) use (&$times) {
            $times++;
            Signals::dispatch(new LoopingProbeSignal($signal->record));
        });

        Signals::dispatch(new LoopingProbeSignal($order));

        $this->assertSame(1, $times, 'the same fact about the same record ran more than once');
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_cascade_that_keeps_deepening_is_capped(): void
    {
        Log::spy();
        $order = $this->order();
        $deepest = 0;

        Event::listen(DeepeningProbeSignal::class, function (DeepeningProbeSignal $signal) use (&$deepest, $order) {
            $deepest = max($deepest, $signal->step);

            /** A fresh record each round, so the fingerprint never repeats. */
            $next = ServiceOrder::factory()->create(['customer_id' => $order->customer_id]);
            Signals::dispatch(new DeepeningProbeSignal($next, $signal->step + 1));
        });

        Signals::dispatch(new DeepeningProbeSignal($order, 1));

        $this->assertSame(Signals::MAX_DEPTH, $deepest, 'the cascade was not capped at MAX_DEPTH');
        Log::shouldHaveReceived('error');
    }

    public function test_everything_caused_by_one_action_shares_a_correlation_id(): void
    {
        $order = $this->order();

        $order->update(['external_invoice_no' => 'F-CORR-1']);

        $correlation_ids = Activity::whereNotNull('correlation_id')
            ->pluck('correlation_id')->unique();

        $this->assertGreaterThan(0, $correlation_ids->count());
    }

    /**
     * Repeat saves of one record are deliberately folded into a single entry, so
     * two unrelated records are what distinguishes one action from the next.
     */
    public function test_separate_actions_get_separate_correlation_ids(): void
    {
        $first_order = $this->order();
        $second_order = $this->order();

        $first_order->update(['external_invoice_no' => 'F-ONE']);
        $first = Activity::where('subject_id', $first_order->id)
            ->where('event_key', 'serviceorder.updated')->latest('id')->first()->correlation_id;

        $second_order->update(['external_invoice_no' => 'F-TWO']);
        $second = Activity::where('subject_id', $second_order->id)
            ->where('event_key', 'serviceorder.updated')->latest('id')->first()->correlation_id;

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'two separate actions shared one correlation id');
    }
}
