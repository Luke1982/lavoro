<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Tests\TestCase;

/**
 * De doctor hoort te melden wanneer /api zijn sessie kwijtraakt.
 *
 * Verzoeken van de planner lopen door de eigen pijplijn van Sanctum, en die
 * slaat hij over zodra hij het verzoek niet herkent als afkomstig van de eigen
 * voorkant. De schermen doen het dan gewoon en de planner geeft op elke
 * handeling 'Unauthenticated'. Aan de app is dat niet te zien, dus moet de
 * doctor het zeggen.
 */
class ApiAuthenticationDoctorTest extends TestCase
{
    private function doctorOutput(): string
    {
        Artisan::call('tenancy:doctor');

        return Artisan::output();
    }

    public function test_it_names_the_address_the_frontend_has_to_come_from(): void
    {
        config(['app.url' => 'https://lavoro.example']);

        $this->assertStringContainsString('APP_URL is https://lavoro.example', $this->doctorOutput());
    }

    public function test_it_complains_when_the_app_address_is_not_stateful(): void
    {
        config(['app.url' => 'https://lavoro.example', 'sanctum.stateful' => ['ergens-anders.example']]);

        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS', $this->doctorOutput());
    }

    /** Op een server is localhost nooit het adres waarop klanten binnenkomen. */
    public function test_it_complains_about_a_local_address_on_a_server(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['env'] = 'production';

        $this->assertStringContainsString('APP_URL wijst naar localhost', $this->doctorOutput());
    }

    public function test_it_complains_when_api_requests_lose_their_tenant(): void
    {
        config(['sanctum.middleware.authenticate_session' => AuthenticateSession::class]);

        $this->assertStringContainsString('authenticate_session', $this->doctorOutput());
    }

    public function test_it_is_quiet_when_everything_lines_up(): void
    {
        config(['app.url' => 'https://lavoro.example', 'sanctum.stateful' => ['lavoro.example']]);

        $output = $this->doctorOutput();

        $this->assertStringContainsString('lavoro.example telt als eigen voorkant', $output);
        $this->assertStringContainsString('api-verzoeken krijgen hun klant mee', $output);
        $this->assertStringContainsString('api-verzoeken mogen de sessie gebruiken', $output);
    }
}
