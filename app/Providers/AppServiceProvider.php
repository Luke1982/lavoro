<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transports\GraphTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('graph', function () {
            return new GraphTransport(
                tenantId: config('services.graph.tenant_id'),
                clientId: config('services.graph.client_id'),
                clientSecret: config('services.graph.client_secret'),
                fromAddress: config('mail.from.address'),
                userId: config('services.graph.user_id'),
                graphEndpoint: config('services.graph.endpoint'),
                dispatcher: app('events'),
                logger: app('log')->channel()
            );
        });
    }
}
