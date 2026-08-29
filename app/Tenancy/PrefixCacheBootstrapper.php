<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class PrefixCacheBootstrapper implements TenancyBootstrapper
{
    protected ?string $original_prefix = null;

    public function __construct(protected Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->original_prefix = $this->app['config']->get('cache.prefix');
        $this->app['config']->set('cache.prefix', 'tenant_' . $tenant->getTenantKey());
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
    }

    public function revert(): void
    {
        $this->app['config']->set('cache.prefix', $this->original_prefix);
        $this->app['cache']->forgetDriver($this->app['config']->get('cache.default'));
        $this->original_prefix = null;
    }
}
