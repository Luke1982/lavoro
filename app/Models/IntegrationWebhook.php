<?php

namespace App\Models;

use App\Domain\Integrations\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook a linked system calls for one area. The token is the last part of
 * the address it calls, stored encrypted here; the central lookup finds the
 * customer by its hash. A connector may also use it as proof of origin, as
 * Business Central's clientState.
 */
class IntegrationWebhook extends Model
{
    protected $fillable = [
        'integration_connection_id',
        'area',
        'external_id',
        'token',
        'expires_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'area' => Area::class,
        'token' => 'encrypted',
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
