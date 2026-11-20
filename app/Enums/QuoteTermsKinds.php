<?php

namespace App\Enums;

/**
 * How the general terms come with a quote: a PDF the company uploads, a text
 * written in the settings and handed over as a PDF, or a link to the page
 * where the company keeps them.
 */
enum QuoteTermsKinds: string
{
    case pdf = 'pdf';
    case text = 'text';
    case link = 'link';
}
