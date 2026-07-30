<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\PageContext;
use App\Domain\Assistant\ReferenceCheck;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every path the assistant deals in has to be a page that exists.
 *
 * Two lists of resource names, written by hand, checked against nothing: the paths
 * it may link to and the paths it recognises as "you are looking at this". Both
 * had "events" on them and there is no such page — appointments live in the
 * planner — so a link to one passed the honesty check and then led nowhere. And a
 * location choice pointed at /customers/1911/7, which is not a route in any sense.
 *
 * Nothing about either mistake shows up until somebody clicks.
 */
class LinkablePathTest extends TestCase
{
    /** @return array<int, string> */
    private function pathsWithAnId(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (preg_match('#^([a-z][a-z-]*)/\{[a-z_]+\}$#', $route->uri(), $found)) {
                $paths[] = $found[1];
            }
        }

        return array_unique($paths);
    }

    public function test_everything_the_assistant_may_link_to_is_a_real_page(): void
    {
        $missing = array_diff(ReferenceCheck::RESOURCES, $this->pathsWithAnId());

        $this->assertSame([], array_values($missing), 'these link nowhere: ' . implode(', ', $missing));
    }

    /**
     * The other direction: a page it thinks it is on has to be somewhere it can
     * actually be, or the context is describing a screen nobody can reach.
     */
    public function test_every_record_page_it_recognises_is_a_real_page(): void
    {
        $missing = array_diff(array_keys(PageContext::RECORDS), $this->pathsWithAnId());

        $this->assertSame([], array_values($missing), 'no such record page: ' . implode(', ', $missing));
    }

    public function test_every_overview_it_recognises_is_a_real_page(): void
    {
        $overviews = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && preg_match('#^[a-z][a-z-]*$#', $route->uri())) {
                $overviews[] = $route->uri();
            }
        }

        $missing = array_diff(array_keys(PageContext::SCREENS), $overviews);

        $this->assertSame([], array_values($missing), 'no such overview: ' . implode(', ', $missing));
    }
}
