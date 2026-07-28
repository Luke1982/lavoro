<?php

namespace Tests\Feature\Signals;

use App\Models\Activity;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\MaintenanceContract;
use App\Models\Material;
use App\Models\Project;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trail is read by Dutch speaking users. A field without a label falls back
 * to a humanised column name, which is English, so the log ends up mixing the
 * two. These keep that from shipping.
 */
class ActivityLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** Models that record history and therefore need a label for every field. */
    private const MODELS = [
        ServiceOrder::class,
        MaintenanceContract::class,
        Ticket::class,
        ServiceJob::class,
        Asset::class,
        Customer::class,
        Project::class,
        Material::class,
        Event::class,
        ServiceOrderTaskInstance::class,
    ];

    public function test_every_logged_field_has_a_dutch_label(): void
    {
        $unlabelled = [];

        foreach (self::MODELS as $class) {
            $model = new $class;
            $reflection = new \ReflectionClass($model);

            $labels = $reflection->hasProperty('activity_labels')
                ? $reflection->getProperty('activity_labels')->getDefaultValue() : [];
            $ignored = $reflection->hasProperty('activity_ignore')
                ? $reflection->getProperty('activity_ignore')->getDefaultValue() : [];

            foreach ($model->getFillable() as $field) {
                if (isset($labels[$field]) || in_array($field, $ignored, true)) {
                    continue;
                }

                $unlabelled[] = class_basename($class) . '::' . $field;
            }
        }

        $this->assertSame(
            [],
            $unlabelled,
            "\nThese fields would be logged with an English fallback label:\n"
                . implode("\n", $unlabelled) . "\n"
        );
    }

    public function test_a_time_that_only_changed_format_is_not_logged_as_a_change(): void
    {
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'actual_start_time' => '07:30:00',
        ]);

        $before = Activity::where('event_key', 'serviceorder.updated')->count();

        $order->update(['actual_start_time' => '07:30']);

        $this->assertSame(
            $before,
            Activity::where('event_key', 'serviceorder.updated')->count(),
            'the same time in another format was recorded as a change'
        );
    }

    public function test_a_real_time_change_is_still_logged(): void
    {
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'actual_start_time' => '07:30:00',
        ]);

        $order->update(['actual_start_time' => '08:15']);

        $change = Activity::with('fieldChanges')
            ->where('event_key', 'serviceorder.updated')->latest('id')->first()
            ->fieldChanges->firstWhere('field', 'actual_start_time');

        $this->assertNotNull($change);
        $this->assertSame('Werkelijke starttijd', $change->label);
    }
}
