<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class AccessTokenTenantLookup extends Model
{
    protected $connection = 'central';

    protected $table = 'access_token_tenant_lookups';

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = ['token_hash', 'tenant_id'];
}
