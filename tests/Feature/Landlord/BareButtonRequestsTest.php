<?php

namespace Tests\Feature\Landlord;

use App\Http\Requests\ForgetIntegrationSecretRequest;
use App\Http\Requests\Landlord\DestroySuperAdminRequest;
use Tests\TestCase;

/**
 * Knoppen in het beheer die alleen een token meesturen: geen invoervelden,
 * geen formulier. Hangt er een verzoek aan dat iets verplicht stelt, dan
 * strandt de knop op "veld is verplicht" terwijl er met de actie niets mis is.
 *
 * Dat is twee keer gebeurd, allebei doordat het aanmaakverzoek werd
 * hergebruikt voor het verwijderen. Vandaar deze lijst: een nieuwe kale knop
 * hoort hier bij te komen.
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
