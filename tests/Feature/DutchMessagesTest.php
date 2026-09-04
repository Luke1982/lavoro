<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * De applicatie is Nederlands, dus de meldingen van Laravel zelf ook.
 *
 * Zonder APP_LOCALE=nl valt Laravel terug op het Engels en staat er "The collect
 * on field is required" midden in een Nederlands formulier. De vertalingen zijn
 * er wel; alleen de instelling ontbrak, en setup-env.sh zette hem niet.
 */
class DutchMessagesTest extends TestCase
{
    public function test_validation_messages_are_dutch(): void
    {
        $this->assertSame('nl', config('app.locale'),
            'APP_LOCALE hoort nl te zijn; anders komen de meldingen in het Engels.');

        $this->assertSame('Incassodatum is verplicht.',
            __('validation.required', ['attribute' => 'incassodatum']));
    }

    /** Het installatiescript hoort de taal te zetten, niet de installateur. */
    public function test_the_setup_script_sets_the_language(): void
    {
        $this->assertStringContainsString('set_key APP_LOCALE nl',
            file_get_contents(base_path('scripts/tenancy/setup-env.sh')));
    }
}
