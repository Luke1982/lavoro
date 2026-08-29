<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class UserTenantLookup extends Model
{
    protected $connection = 'central';
    protected $table = 'user_tenant_lookups';
    protected $primaryKey = 'email';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['email', 'tenant_id'];
}
