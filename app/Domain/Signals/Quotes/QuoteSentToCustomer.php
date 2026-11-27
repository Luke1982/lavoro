<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;

/**
 * A quote went to the customer by mail, with a link to answer it on. The first
 * time it also moves the quote to "verzonden", which this fact then tells; sent
 * again after a change, the status stays and only the mail is news. Either way
 * the trail says to whom, until when the link works, and which files shared
 * with the customer went along.
 */
class QuoteSentToCustomer extends QuoteStatusChanged
{
    /** @param  array<int, string>  $attachments  the files shared with the customer, by name */
    public function __construct(
        Quote $quote,
        string $previous_status,
        string $new_status,
        public string $recipient,
        public ?\DateTimeInterface $expires_at,
        public int $version = 1,
        public array $attachments = [],
    ) {
        parent::__construct($quote, $previous_status, $new_status);
    }

    public static function key(): string
    {
        return 'quote.sent_to_customer';
    }

    public static function label(): string
    {
        return 'Offerte verstuurd';
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function activityDescription(): ?string
    {
        return 'Versie ' . $this->version . ' van offerte ' . $this->quote->label()
            . ' per e-mail verstuurd aan ' . $this->recipient
            . ($this->expires_at ? ', de link werkt tot en met ' . $this->expires_at->format('d-m-Y') : '')
            . ($this->attachments ? ', met ' . implode(', ', $this->attachments) : '');
    }

    public function activityMetadata(): ?array
    {
        return ['recipient' => $this->recipient, 'version' => $this->version, 'attachments' => $this->attachments];
    }

    public function changes(): array
    {
        return $this->previous_status === $this->new_status ? [] : parent::changes();
    }
}
