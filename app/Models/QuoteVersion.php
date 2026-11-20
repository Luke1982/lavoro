<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quote as the customer received it at one moment: the heading, the texts,
 * the lines and the totals, frozen. The page behind a link shows the version
 * that link went out with, never what the quote became since.
 */
class QuoteVersion extends Model
{
    protected $fillable = ['quote_id', 'number', 'snapshot', 'recipient', 'access_token_id', 'created_by'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
