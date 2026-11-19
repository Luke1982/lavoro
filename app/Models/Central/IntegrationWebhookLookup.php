<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class IntegrationWebhookLookup extends Model
{
    protected $connection = 'central';

    protected $table = 'integration_webhook_lookups';

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = ['token_hash', 'tenant_id', 'integration_webhook_id'];
}
