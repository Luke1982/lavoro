<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandardAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'original_filename',
        'mime_type',
        'size',
    ];

    public function standardEmails(): BelongsToMany
    {
        return $this->belongsToMany(StandardEmail::class, 'standard_email_standard_attachment')
            ->withTimestamps();
    }
}
