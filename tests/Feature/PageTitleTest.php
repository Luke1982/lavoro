<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PageTitle;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The title bar should read "Lavoro - <module> - <customer>".
 *
 * It said "Laravel", twice over. First app.blade.php used env(), which after
 * config:cache returns its default -- literally 'Laravel'. Then the brand came
 * from APP_NAME, and a server whose .env never set it has Laravel's default
 * there too.
 */
class PageTitleTest extends TestCase
{
    public function test_the_title_names_the_module_and_the_tenant(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();

        $this->assertSame(PageTitle::brand() . ' - Dashboard - ' . tenancy()->tenant->name, $this->titleOf($response->getContent()));
    }

    public function test_laravel_never_makes_it_into_the_tab(): void
    {
        config(['app.name' => 'Laravel']);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('title', 'Lavoro - Dashboard - ' . tenancy()->tenant->name));
    }

    public function test_a_screen_takes_the_name_of_the_menu_item_it_hangs_under(): void
    {
        $this->assertSame('Werkbonnen', PageTitle::module('/serviceorders/12'));
        $this->assertSame('Producttypes', PageTitle::module('/producttypes'), 'a submenu item is a module too');
        $this->assertSame('Dashboard', PageTitle::module('/'));
        $this->assertNull(PageTitle::module('/nergens'), 'the dashboard does not swallow every path');
    }

    /**
     * With a cached configuration too, because that is precisely the case where
     * it went wrong: env() then falls back on its default value.
     */
    public function test_it_survives_a_cached_configuration(): void
    {
        $this->assertStringNotContainsString(
            "env('APP_NAME'",
            file_get_contents(resource_path('views/app.blade.php')),
            'app.blade.php reads the name with env(); that becomes "Laravel" once config:cache has run.'
        );
    }

    private function titleOf(string $html): string
    {
        preg_match('#<title[^>]*>(.*?)</title>#s', $html, $found);

        return trim($found[1] ?? '');
    }
}
