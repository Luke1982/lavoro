<?php

namespace App\Models;

use App\Domain\Quotes\QuoteForCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send of a quote: the version as the customer received it at that moment,
 * frozen, with the address it went to. The number is the version whoever made
 * the quote was working on, so sending the same version twice gives two rows of
 * one number. The page behind a link shows the send that link belongs to,
 * never what the quote became since.
 */
class QuoteVersion extends Model
{
    protected $fillable = ['quote_id', 'number', 'snapshot', 'recipient', 'access_token_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'viewed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** The link this send went out with. */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(AccessToken::class);
    }

    /** Whether the customer can still answer this send: its link was not answered, withdrawn or let run out. */
    public function isOpen(): bool
    {
        return $this->accessToken?->isUsable() ?? false;
    }

    /**
     * The send a link shows: the one it went out with, or, for a reminder, the
     * one it was sent again for.
     */
    public static function shownBy(AccessToken $link): ?self
    {
        $version_id = $link->payload['version_id'] ?? null;

        return $version_id
            ? static::find($version_id)
            : static::where('access_token_id', $link->id)->first();
    }

    /** @return array<string, mixed> the snapshot with everything a page or a PDF reads */
    public function document(): array
    {
        return QuoteForCustomer::complete($this->snapshot, $this->number);
    }

    /**
     * As the quote page lists it: when, to whom and for how much, whether the
     * customer opened it, and whether it is the one that was accepted. The one
     * accepted is worth what was signed, with the choices made.
     *
     * @return array<string, mixed>
     */
    public function toListItem(Quote $quote): array
    {
        $accepted = $this->id === $quote->accepted_version_id;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'created_at' => $this->created_at,
            'recipient' => $this->recipient,
            'net' => ($accepted ? $quote->signedDocument() : $this->snapshot)['totals']['net'] ?? null,
            'viewed_at' => $this->viewed_at,
            'reminded_at' => $this->reminded_at,
            'accepted' => $accepted,
        ];
    }
}
