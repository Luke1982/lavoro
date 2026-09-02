<?php

use App\Models\Tenant;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Tenancy\PrefixCacheBootstrapper;
use App\Tenancy\TenantStorageBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\UUIDGenerator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,
    'central_domains' => [],
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        PrefixCacheBootstrapper::class,
        TenantStorageBootstrapper::class,
    ],
    'database' => [
        'central_connection' => 'central',
        'template_tenant_connection' => env('DB_CONNECTION', 'mysql'),
        'prefix' => env('TENANCY_DB_PREFIX', 'lavoro_tenant_'),
        /** Deelt de rechten van een klantlogin uit; zie scripts/tenancy/setup-mysql.sh. */
        'grant_procedure' => env('TENANCY_GRANT_PROCEDURE', 'lavoro_admin.grant_tenant_access'),
        'suffix' => '',
        'managers' => [
            'mysql' => env('TENANCY_MYSQL_MANAGER', TenantDatabaseManager::class),
            'mariadb' => env('TENANCY_MYSQL_MANAGER', TenantDatabaseManager::class),
        ],
    ],
    'cache' => ['tag_base' => 'tenant'],
    'filesystem' => ['suffix_base' => 'tenant', 'disks' => [], 'root_override' => []],
    'redis' => ['prefix_base' => 'tenant', 'prefixed_connections' => []],
    'features' => [],
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],
    'seeder_parameters' => ['--class' => 'Database\Seeders\TenantDatabaseSeeder'],
];
