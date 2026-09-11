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
        /** Hands out a customer login's rights; see scripts/tenancy/setup-mysql.sh. */
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
    /**
     * Both need --force: in production, tenants:migrate and tenants:seed ask
     * "are you sure?", and when Tenant::create() runs them nobody sees that
     * question. From a terminal it waits forever; from a worker the answer
     * is no and the step is skipped without a word.
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],
    'seeder_parameters' => [
        '--force' => true,
        '--class' => 'Database\Seeders\TenantDatabaseSeeder',
    ],
];
