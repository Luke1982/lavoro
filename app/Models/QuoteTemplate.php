<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quote kept to start others from. The content is what QuoteContent::of
 * took from the quote it was saved from: its texts, its terms and its lines.
 */
class QuoteTemplate extends Model
{
    protected $fillable = ['name', 'content', 'created_by'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** How many lines it starts a quote with, the materials under a product included. */
    public function lineCount(): int
    {
        return collect($this->content['lines'] ?? [])
            ->sum(fn (array $line) => 1 + count($line['children'] ?? []));
    }
}
