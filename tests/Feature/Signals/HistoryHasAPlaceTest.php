<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\BaseSignal;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Tests\Concerns\BuildsEverySignal;
use Tests\TestCase;

/**
 * An entry in the trail is only ever read where it is attached, and it is only
 * attached to records with a timeline. History about a record without one is
 * written, and then nobody can read it: no screen shows it and the assistant cannot
 * find it. Nothing fails when that happens -- a missing entry looks exactly like
 * nothing having happened -- which is how every change to a machine went unseen.
 * These make it fail.
 */
class HistoryHasAPlaceTest extends TestCase
{
    use BuildsEverySignal;

    public function test_every_model_with_history_has_a_timeline_or_names_one(): void
    {
        $problems = [];

        foreach ($this->modelsWithHistory() as $class) {
            if ($this->hasTimeline($class) || $this->overrides($class, 'historyTimelines')) {
                continue;
            }

            $problems[] = class_basename($class) . ' records history but has no timeline (HasActivities) '
                . 'and does not say where its history goes (historyTimelines())';
        }

        $this->assertSame([], $problems, "\n" . implode("\n", $problems) . "\n");
    }

    /**
     * "Aangemaakt" on a customer's timeline says nothing unless the entry also says
     * what was made.
     */
    public function test_every_model_that_lands_on_another_timeline_says_what_it_is(): void
    {
        $problems = [];

        foreach ($this->modelsWithHistory() as $class) {
            if ($this->overrides($class, 'historyTimelines') && !$this->overrides($class, 'historyLabel')) {
                $problems[] = class_basename($class) . ' puts its history on other timelines but has no historyLabel()';
            }
        }

        $this->assertSame([], $problems, "\n" . implode("\n", $problems) . "\n");
    }

    /**
     * A signal about a record without a timeline is lost unless it names other
     * records it belongs on. A signal that takes any record (an image on whatever
     * it hangs off) is checked once for every record it can be about.
     */
    public function test_every_signal_that_writes_an_entry_can_be_seen_somewhere(): void
    {
        $problems = [];

        foreach ($this->everySignal() as $name => $signal) {
            $subject = $signal->subject();

            if ($this->hasTimeline($subject::class)) {
                continue;
            }

            if ($this->overrides($signal::class, 'activityContext') || $this->writesNothing($signal)) {
                continue;
            }

            $problems[] = $name . ' is about a ' . class_basename($subject)
                . ', which has no timeline, and names no other record it belongs on';
        }

        $this->assertSame([], $problems, "\n" . implode("\n", $problems) . "\n");
    }

    private function hasTimeline(string $class): bool
    {
        return in_array(HasActivities::class, class_uses_recursive($class), true);
    }

    /** Whether the class itself declares the method, rather than inheriting the default. */
    private function overrides(string $class, string $method): bool
    {
        if (!method_exists($class, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($class, $method);
        $defaults = [
            (new \ReflectionClass(RecordsHistory::class))->getFileName(),
            (new \ReflectionClass(BaseSignal::class))->getFileName(),
        ];

        return !in_array($reflection->getFileName(), $defaults, true);
    }

    private function writesNothing(object $signal): bool
    {
        try {
            return $signal->activityDescription() === null && $signal->changes() === [];
        } catch (\Throwable) {
            return false;
        }
    }
}
