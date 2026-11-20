<?php

namespace App\Models;

use App\Domain\Integrations\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook a linked system calls for one area. The token is the last part of
 * the address it calls, stored encrypted here; the central lookup finds the
 * customer by its hash. A connector may also use it as proof of origin, as
 * Business Central's clientState. The signing secret is the other system's
 * own, for one that signs its notices with a secret it hands out (Moneybird).
 */
class IntegrationWebhook extends Model
{
    protected $fillable = [
        'integration_connection_id',
        'area',
        'external_id',
        'token',
        'signing_secret',
        'scope',
        'expires_at',
    ];

    protected $hidden = ['token', 'signing_secret'];

    protected $casts = [
        'area' => Area::class,
        'token' => 'encrypted',
        'signing_secret' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    /**
     * Not connection(): every model already has a $connection, the name of its
     * database connection, and the relation would read as that string.
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
