<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Reading\RecordKinds;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every model with a policy is either readable by the assistant or kept out of
 * it with a reason.
 *
 * Readable is a kind with a noun in RecordKinds::NOUNS whose policy answers
 * viewAny. What this catches is a policy nobody decided about: one without
 * viewAny, or one whose model has no noun, both of which leave the model out of
 * the assistant without anybody having said so. Either give the policy viewAny
 * (and the model a visibleTo scope when view depends on the record) and the
 * model its noun, or put the model in RecordKinds::UNREADABLE with the reason it
 * stays out.
 *
 * See docs/development/assistant-read-layer.md.
 */
class ReadLayerCoverageTest extends TestCase
{
    public function test_every_model_with_a_policy_is_readable_or_excluded_with_a_reason(): void
    {
        $undecided = [];

        foreach (RecordKinds::withPolicy() as $class => $policy) {
            if (isset(RecordKinds::UNREADABLE[$class])) {
                continue;
            }

            if (!method_exists($policy, 'viewAny')) {
                $undecided[] = class_basename($class) . ' (' . class_basename($policy) . ' has no viewAny)';

                continue;
            }

            if (!isset(RecordKinds::NOUNS[$class])) {
                $undecided[] = class_basename($class) . ' (no Dutch noun in RecordKinds::NOUNS)';
            }
        }

        $this->assertSame([], $undecided, 'neither readable nor excluded: ' . implode(', ', $undecided));
    }

    public function test_every_exclusion_is_a_model_with_a_policy_and_says_why(): void
    {
        $policed = RecordKinds::withPolicy();
        $wrong = [];

        foreach (RecordKinds::UNREADABLE as $class => $reason) {
            if (!isset($policed[$class])) {
                $wrong[] = class_basename($class) . ' has no policy, so it needs no exclusion';
            }

            if (trim($reason) === '') {
                $wrong[] = class_basename($class) . ' is excluded without a reason';
            }
        }

        $this->assertSame([], $wrong, implode('; ', $wrong));
    }

    /**
     * A link the assistant hands out has to lead to a page that takes the filter,
     * and the sentence above it needs the kind's plural.
     */
    public function test_every_overview_the_assistant_links_to_exists_and_takes_the_filter(): void
    {
        $overviews = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $overviews[$route->uri()] = $route->getActionName();
            }
        }

        $wrong = [];

        foreach (RecordKinds::overviews() as $class => $path) {
            if (!isset($overviews[$path])) {
                $wrong[] = $path . ' is not a page';

                continue;
            }

            if (!str_contains($this->sourceOf($overviews[$path]), 'AssistantFilter::from(')) {
                $wrong[] = $path . ' does not apply AssistantFilter in ' . $overviews[$path];
            }

            if (!isset(RecordKinds::PLURALS[$class])) {
                $wrong[] = $path . ' has no plural in RecordKinds::PLURALS';
            }
        }

        $this->assertSame([], $wrong, implode('; ', $wrong));
    }

    /**
     * The source of the one action a route runs, not of its whole controller: a
     * filter applied in some other method of the same class does not count.
     */
    private function sourceOf(string $action): string
    {
        [$controller, $method] = array_pad(explode('@', $action), 2, '__invoke');

        if (!method_exists($controller, $method)) {
            return '';
        }

        $reflection = new \ReflectionMethod($controller, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}
