<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The title bar should read "Lavoro - <customer> - <part>".
 *
 * It said "Laravel", and that was not sloppiness but a trap: app.blade.php used
 * env(), and once config:cache has run env() outside the configuration files
 * returns its default value -- literally 'Laravel'. In development it never
 * showed, in production it was in every tab.
 */
class PageTitleTest extends TestCase
{
    public function test_the_title_names_lavoro_and_the_tenant(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();

        $title = $this->titleOf($response->getContent());

        $this->assertStringStartsWith(config('app.name'), $title);
        $this->assertStringContainsString((string) tenancy()->tenant->name, $title);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $title);
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
            'app.blade.php leest de naam met env(); dat wordt "Laravel" zodra config:cache heeft gedraaid.'
        );
    }

    private function titleOf(string $html): string
    {
        preg_match('#<title[^>]*>(.*?)</title>#s', $html, $found);

        return trim($found[1] ?? '');
    }
}
