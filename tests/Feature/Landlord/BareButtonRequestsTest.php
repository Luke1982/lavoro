<?php

namespace Tests\Feature\Landlord;

use App\Http\Requests\ForgetIntegrationSecretRequest;
use App\Http\Requests\Landlord\DestroySuperAdminRequest;
use Tests\TestCase;

/**
 * Buttons in the admin panel that only send a token: no input fields, no form.
 * Hang a request on one that requires something, and the button strands on
 * "field is required" while there is nothing wrong with the action.
 *
 * That happened twice, both times because the create request was reused for
 * deleting. Hence this list: a new bare button belongs in it.
 */
class BareButtonRequestsTest extends TestCase
{
    public static function bareButtons(): array
    {
        return [
            'superbeheerder verwijderen' => [DestroySuperAdminRequest::class],
            'sleutel wissen' => [ForgetIntegrationSecretRequest::class],
        ];
    }

    /**
     * @dataProvider bareButtons
     */
    public function test_the_request_asks_for_nothing(string $request): void
    {
        $rules = (new $request)->rules();

        $this->assertSame(
            [],
            $rules,
            class_basename($request) . ' hoort niets te vragen; de knop stuurt alleen een token mee.',
        );
    }
}
