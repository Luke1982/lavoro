<?php

namespace App\Providers;

use App\Listeners\CopyMailToSentFolder;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transports\GraphTransport;
use Inertia\Inertia;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;

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

        Event::listen(MessageSent::class, CopyMailToSentFolder::class);

        Inertia::share('company', function () {
            $company = Company::where('is_main', true)->first();
            if (!$company) {
                return null;
            }
            $logo_url = $company->logo_path ? asset('storage/' . $company->logo_path) : null;
            return [
                'name' => $company->name,
                'logo_url' => $logo_url,
            ];
        });
    }
}
