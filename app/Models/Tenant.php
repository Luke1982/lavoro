<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $connection = 'central';

    protected $casts = [
        'data' => 'array',
        'modules' => 'array',
        'extra_field_seats' => 'integer',
        'extra_office_seats' => 'integer',
        'price_override_cents' => 'integer',
        'storage_limit_gb' => 'integer',
        'tenancy_db_password' => 'encrypted',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'package_key', 'extra_field_seats', 'extra_office_seats',
            'modules', 'price_override_cents', 'storage_limit_gb',
            'tenancy_db_username', 'tenancy_db_password',
        ];
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
