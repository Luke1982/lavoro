<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * One of MajorLabel's own accounts inside a customer, kept in the landlord
 * database.
 *
 * This row is the record of it; the user in the customer's own database is a
 * copy that can be made again from here. See App\Services\TenantSuperAdmins.
 *
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class TenantSuperAdmin extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_super_admins';

    protected $fillable = ['tenant_id', 'name', 'email', 'password'];

    protected $hidden = ['password'];

    /**
     * Deliberately not the 'hashed' cast: the value handed around here is
     * already a hash, copied from the customer's database or made once when the
     * account was handed out. The cast would leave a hash alone, but it would
     * also quietly hash anything else, and this column must never hold a
     * readable password.
     */
    protected $casts = [];
}
