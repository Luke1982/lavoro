<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\InitializeTenancyBySession;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareOrderTest extends TestCase
{
    public function test_tenancy_is_initialized_before_bindings_and_auth(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'serviceorders/{serviceorder}');

        $this->assertNotNull($route, 'De route waar deze test op let bestaat niet meer.');

        $order = collect(app(Router::class)->gatherRouteMiddleware($route))
            ->map(fn ($m) => is_string($m) ? $m : $m::class)
            ->values();

        $at = fn (string $needle) => $order->search(fn ($m) => str_starts_with($m, $needle));

        $tenancy = $at(InitializeTenancyBySession::class);
        $bindings = $at(\Illuminate\Routing\Middleware\SubstituteBindings::class);
        $auth = $at(\Illuminate\Auth\Middleware\Authenticate::class);

        $this->assertNotFalse($tenancy, 'InitializeTenancyBySession staat niet op deze route.');

        $this->assertLessThan($bindings, $tenancy,
            'Tenancy moet voor SubstituteBindings staan, anders zoekt elk gebonden model in de centrale database en geeft 404.');

        $this->assertLessThan($auth, $tenancy,
            'Tenancy moet voor auth staan, want auth zoekt de gebruiker in de tenantdatabase.');
    }
}
