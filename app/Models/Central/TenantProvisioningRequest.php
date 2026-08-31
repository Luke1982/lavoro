<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 *
 * @method static static create(array $attributes = [])
 */
class TenantProvisioningRequest extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_provisioning_requests';

    protected $fillable = [
        'action', 'status', 'tenant_id', 'name', 'email',
        'package_key', 'modules', 'error', 'generated_password', 'finished_at',
    ];

    protected $casts = [
        'modules' => 'array',
        'finished_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
