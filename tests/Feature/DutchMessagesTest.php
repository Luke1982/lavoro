<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The application is Dutch, so Laravel's own messages are too.
 *
 * Without APP_LOCALE=nl Laravel falls back on English and "The collect on field
 * is required" sits in the middle of a Dutch form. The translations are there;
 * only the setting was missing, and setup-env.sh did not write it.
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

    /** The install script should set the language, not the installer. */
    public function test_the_setup_script_sets_the_language(): void
    {
        $this->assertStringContainsString('set_key APP_LOCALE nl',
            file_get_contents(base_path('scripts/tenancy/setup-env.sh')));
    }
}
