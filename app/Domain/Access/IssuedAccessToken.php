<?php

namespace App\Domain\Access;

use App\Models\AccessToken;

/**
 * Een net uitgegeven link, één keer, met de leesbare waarde er nog bij.
 *
 * Bestaat omdat de url alleen op dit moment te maken is: daarna staat er enkel
 * nog een hash en is de waarde die in de mail moet nergens meer terug te halen.
 */
final class IssuedAccessToken
{
    public function __construct(
        public AccessToken $token,
        public string $plaintext,
    ) {}

    public function url(): string
    {
        return route($this->token->purpose->routeName(), ['token' => $this->plaintext]);
    }
}
