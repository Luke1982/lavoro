<?php

namespace Tests\Feature\Signals;

use Illuminate\Database\Eloquent\Model;
use Tests\Concerns\BuildsEverySignal;
use Tests\TestCase;

/**
 * Structural guards over the whole signal catalogue.
 *
 * These exist because a signal whose changes() returned a flat array instead of a
 * list of changes shipped silently: the activity row was written, the change rows
 * were not, and a single unrelated test caught it by accident.
 */
class SignalShapeTest extends TestCase
{
    use BuildsEverySignal;

    private const REQUIRED_CHANGE_KEYS = ['field', 'label', 'old_value', 'new_value', 'old_label', 'new_label'];

    public function test_every_signal_that_reports_changes_returns_a_list_of_change_rows(): void
    {
        $problems = [];

        foreach ($this->signalClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->getMethod('changes')->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $signal = $this->instantiate($class);

            if ($signal === null) {
                continue;
            }

            foreach ($signal->changes() as $index => $change) {
                if (!is_array($change)) {
                    $problems[] = class_basename($class)
                        . ' returns a flat array from changes(); it must return a list of change rows';

                    break;
                }

                $missing = array_diff(self::REQUIRED_CHANGE_KEYS, array_keys($change));

                if ($missing !== []) {
                    $problems[] = class_basename($class)
                        . " change #{$index} is missing: " . implode(', ', $missing);
                }
            }
        }

        $this->assertSame([], $problems, "\n" . implode("\n", $problems) . "\n");
    }

    public function test_every_signal_reports_a_subject_and_a_category(): void
    {
        $problems = [];

        foreach ($this->signalClasses() as $class) {
            $signal = $this->instantiate($class);

            if ($signal === null) {
                continue;
            }

            if (!$signal->subject() instanceof Model) {
                $problems[] = class_basename($class) . ' does not return a model from subject()';
            }

            if ($signal->activityCategory() === '') {
                $problems[] = class_basename($class) . ' has a blank category';
            }
        }

        $this->assertSame([], $problems, "\n" . implode("\n", $problems) . "\n");
    }
}
