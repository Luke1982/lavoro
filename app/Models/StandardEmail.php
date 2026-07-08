<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandardEmail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'subject',
        'body',
    ];

    public function triggers(): HasMany
    {
        return $this->hasMany(StandardEmailTrigger::class);
    }

    public function standardAttachments(): BelongsToMany
    {
        return $this->belongsToMany(StandardAttachment::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }
}
