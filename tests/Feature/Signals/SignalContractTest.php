<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\BaseSignal;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * A brand new signal, defined nowhere but here. If the interface contract holds,
 * it gets logged without anything being registered for it.
 */
class UnregisteredProbeSignal extends BaseSignal
{
    public function __construct(public Model $record)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'probe.happened';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return 'Er is iets gebeurd';
    }
}

/**
 * Guards the load-bearing assumptions. If any of these break, the event layer
 * stops working silently rather than loudly, which is the dangerous kind.
 */
class SignalContractTest extends TestCase
{

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_a_signal_nobody_registered_is_still_logged(): void
    {
        $order = $this->order();

        event(new UnregisteredProbeSignal($order));

        $activity = Activity::where('event_key', 'probe.happened')->sole();

        $this->assertSame('Er is iets gebeurd', $activity->description);
        $this->assertSame(ServiceOrder::class, $activity->subject_type);
        $this->assertSame($order->id, $activity->subject_id);
    }

    public function test_field_changes_reach_the_frontend_under_the_key_the_timeline_reads(): void
    {
        $order = $this->order();
        $order->update(['external_invoice_no' => 'F-1']);

        $activity = Activity::with('fieldChanges')
            ->where('event_key', 'serviceorder.updated')->sole();

        $payload = $activity->toArray();

        $this->assertArrayHasKey('field_changes', $payload);
        $this->assertSame('Extern factuurnummer', $payload['field_changes'][0]['label']);
    }

    public function test_every_signal_carries_a_key_that_is_never_blank(): void
    {
        $classes = [];
        $base = app_path('Domain/Signals');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([$base . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Domain\\Signals\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(BaseSignal::class)) {
                continue;
            }

            $classes[$class] = $class::key();
            $this->assertNotSame('', $class::key(), $class . ' has a blank key');
            $this->assertNotSame('', $class::label(), $class . ' has a blank label');
        }

        $this->assertNotEmpty($classes, 'expected the signal catalogue to be found');

        $shared = array_diff_assoc($classes, array_unique($classes));
        $this->assertSame(
            [],
            $shared,
            'these signals share a key with another signal: ' . json_encode($shared)
        );
    }
}
