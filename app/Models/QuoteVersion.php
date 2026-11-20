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

    /**
     * As the quote page lists it: when, to whom and for how much, and whether it
     * is the one that was accepted.
     *
     * @return array{id: int, number: int, created_at: mixed, recipient: ?string, net: ?float, accepted: bool}
     */
    public function toListItem(?int $accepted_version_id): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'created_at' => $this->created_at,
            'recipient' => $this->recipient,
            'net' => $this->snapshot['totals']['net'] ?? null,
            'accepted' => $this->id === $accepted_version_id,
        ];
    }
}
