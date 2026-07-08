<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardEmailTrigger extends Model
{
    protected $fillable = [
        'standard_email_id',
        'trigger',
        'trigger_type',
    ];

    public function standardEmail(): BelongsTo
    {
        return $this->belongsTo(StandardEmail::class);
    }
}
