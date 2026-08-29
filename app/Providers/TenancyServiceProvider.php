<?php

namespace App\Providers;

use App\Support\TenantState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\JobPipeline\JobPipeline;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        Event::listen(TenancyInitialized::class, fn () => TenantState::flush());
        Event::listen(TenancyEnded::class, fn () => TenantState::flush());

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class, MigrateDatabase::class, SeedDatabase::class])
                ->send(fn (TenantCreated $event) => $event->tenant)
                ->shouldBeQueued(false)
                ->toListener()
        );
    }
}
