<?php

namespace App\Domain\Quotes;

use App\Models\Company;
use App\Models\Quote;

/**
 * What the drawer for sending a quote starts with. Everything here is a
 * suggestion: the colleague changes it before it goes. The button to the quote
 * is not in the text, it is added under it.
 */
final class QuoteMail
{
    /** @return array{to: ?string, subject: string, body: string} what the drawer starts with */
    public static function defaults(Quote $quote): array
    {
        return ['to' => self::defaultRecipient($quote), 'subject' => self::subject($quote), 'body' => self::body($quote)];
    }

    /** The contact person, then the customer's address for quotes, then its general one. */
    public static function defaultRecipient(Quote $quote): ?string
    {
        return $quote->contact?->email ?: $quote->customer?->quotes_email ?: $quote->customer?->email ?: null;
    }

    public static function subject(Quote $quote): string
    {
        return 'Offerte ' . $quote->number . ': ' . $quote->title;
    }

    public static function body(Quote $quote): string
    {
        $greeting = $quote->contact?->full_name ?: $quote->customer?->name ?: 'klant';
        $company = Company::where('is_main', true)->value('name');

        return '<p>Beste ' . e($greeting) . ',</p>'
            . '<p>Hierbij ontvangt u onze offerte ' . e($quote->number) . ' voor ' . e($quote->title) . '. '
            . 'Met de knop hieronder bekijkt u de offerte. Daar kunt u ook direct akkoord geven, '
            . 'of laten weten wat u anders zou willen.</p>'
            . '<p>Met vriendelijke groet,<br>' . e($company ?: '') . '</p>';
    }
}
