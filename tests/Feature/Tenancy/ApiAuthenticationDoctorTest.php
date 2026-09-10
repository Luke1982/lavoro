<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Tests\TestCase;

/**
 * The doctor should report when /api loses its session.
 *
 * The planner's requests run through Sanctum's own pipeline, and it skips that
 * pipeline as soon as it does not recognise the request as coming from its own
 * front end. The screens then work fine and the planner returns
 * 'Unauthenticated' on every action. The app does not show it, so the doctor
 * has to say it.
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
