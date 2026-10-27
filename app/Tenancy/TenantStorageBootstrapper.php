<?php

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class TenantStorageBootstrapper implements TenancyBootstrapper
{
    protected array $original_roots = [];

    public function __construct(protected Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = 'tenant-' . $tenant->getTenantKey();

        foreach (['local', 'public'] as $disk) {
            $this->original_roots[$disk] = $this->app['config']["filesystems.disks.{$disk}.root"];
            $this->app['config']->set("filesystems.disks.{$disk}.root", storage_path("{$suffix}/{$disk}"));
            Storage::forgetDisk($disk);
        }
    }

    public function revert(): void
    {
        foreach ($this->original_roots as $disk => $root) {
            $this->app['config']->set("filesystems.disks.{$disk}.root", $root);
            Storage::forgetDisk($disk);
        }
        $this->original_roots = [];
    }
}
