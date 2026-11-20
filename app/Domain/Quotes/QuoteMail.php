<?php

namespace App\Domain\Quotes;

use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteVersion;

/**
 * The letters a quote goes out with. What the drawer for sending starts with is
 * a suggestion: the colleague changes it before it goes. The reminder and the
 * confirmation of a yes go by themselves, so they say only what is certain. The
 * button to the quote is never in the text, it is added under it.
 */
final class QuoteMail
{
    public const BUTTON = 'Offerte bekijken';

    /** @return array{to: ?string, subject: string, body: string} what the drawer starts with */
    public static function defaults(Quote $quote): array
    {
        return [
            'to' => self::defaultRecipient($quote),
            'subject' => self::subject($quote),
            'body' => self::body($quote),
        ];
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
        return self::greeting($quote)
            . '<p>Hierbij ontvangt u onze offerte ' . e($quote->number) . ' voor ' . e($quote->title) . '. '
            . 'Met de knop hieronder bekijkt u de offerte. Daar kunt u ook direct akkoord geven, '
            . 'of laten weten wat u anders zou willen.</p>'
            . self::closing();
    }

    public static function reminderSubject(Quote $quote): string
    {
        return 'Herinnering: ' . self::subject($quote);
    }

    public static function reminderBody(Quote $quote, QuoteVersion $version): string
    {
        return self::greeting($quote)
            . '<p>Op ' . $version->created_at->format('d-m-Y') . ' stuurden wij u onze offerte ' . e($quote->number)
            . ' voor ' . e($quote->title) . '. Wij hebben nog niets van u gehoord en zijn benieuwd wat u ervan vindt. '
            . 'Met de knop hieronder bekijkt u de offerte en reageert u direct.</p>'
            . ($quote->valid_until
                ? '<p>De offerte is geldig tot en met ' . $quote->valid_until->format('d-m-Y') . '.</p>'
                : '')
            . self::closing();
    }

    public static function confirmationSubject(Quote $quote): string
    {
        return 'Bevestiging van uw akkoord: offerte ' . $quote->number;
    }

    public static function confirmationBody(Quote $quote): string
    {
        $cooling_off = $quote->coolingOffUntil();

        return self::greeting($quote)
            . '<p>Dank u wel voor uw akkoord op offerte ' . e($quote->number) . ' voor ' . e($quote->title) . '. '
            . 'In de bijlage vindt u de offerte met uw handtekening.</p>'
            . ($cooling_off ? '<p>U hebt bedenktijd tot en met ' . $cooling_off->format('d-m-Y') . '. Hoe u de '
                . 'overeenkomst in die tijd herroept, leest u onder Herroepingsrecht in de offerte.</p>' : '')
            . self::closing();
    }

    private static function greeting(Quote $quote): string
    {
        return '<p>Beste ' . e($quote->contact?->full_name ?: $quote->customer?->name ?: 'klant') . ',</p>';
    }

    private static function closing(): string
    {
        return '<p>Met vriendelijke groet,<br>' . e(Company::main()?->name ?? '') . '</p>';
    }
}
