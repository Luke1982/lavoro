<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * De titelbalk hoort "Lavoro - <klant> - <onderdeel>" te zijn.
 *
 * Er stond "Laravel", en dat was geen slordigheid maar een val: app.blade.php
 * gebruikte env(), en zodra config:cache heeft gedraaid geeft env() buiten de
 * configuratiebestanden zijn standaardwaarde terug -- letterlijk 'Laravel'. In
 * ontwikkeling viel dat nooit op, in productie stond het in elke tab.
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
     * Ook met een gecachete configuratie, want dat is precies het geval waarin
     * het misging: env() valt dan terug op zijn standaardwaarde.
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
