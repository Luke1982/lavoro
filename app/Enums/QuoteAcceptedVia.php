<?php

namespace App\Enums;

/** Where the customer said yes to a quote. */
enum QuoteAcceptedVia: string
{
    /** On the page behind the link they were sent. */
    case link = 'link';

    /** On a screen in the app, in front of whoever made the quote. */
    case app = 'app';

    /** How the trail and the quote page put it: "akkoord via de link". */
    public function phrase(): string
    {
        return match ($this) {
            self::link => 'via de link',
            self::app => 'in de app',
        };
    }
}
