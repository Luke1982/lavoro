<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Which record here is which record in a linked system, and how both looked at
 * the last sync.
 *
 * The fingerprints, one per field and side, are what make two-way sync
 * possible without keeping a copy of every record: a field that no longer
 * matches local_fingerprints changed here, one that no longer matches
 * remote_fingerprints changed there, and only a field that moved on both sides
 * is a clash for Leading to settle (see Sync\Merge).
 *
 * confirmed_at is set the first time the other side is seen to have the
 * record. A link that was never confirmed and is not there on a full pull was
 * never real (the made-up SnelStart ids of before) and is dropped; a confirmed
 * one that disappears gets missing_at, and is left alone after that.
 */
class Integrationable extends Model
{
    protected $fillable = [
        'integration_connection_id',
        'integrationable_type',
        'integrationable_id',
        'external_id',
        'local_fingerprints',
        'remote_fingerprints',
        'confirmed_at',
        'missing_at',
        'synced_at',
    ];

    protected $casts = [
        'local_fingerprints' => 'array',
        'remote_fingerprints' => 'array',
        'confirmed_at' => 'datetime',
        'missing_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Not connection(): every model already has a $connection, the name of its
     * database connection, and the relation would read as that string.
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function integrationable(): MorphTo
    {
        return $this->morphTo();
    }
}
