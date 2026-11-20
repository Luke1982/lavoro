<?php

namespace App\Domain\Integrations\Contracts;

/**
 * The customer connects by logging in at the other system and allowing
 * Lavoro in, instead of typing in keys. The app on the other side is
 * MajorLabel's own -- one registration for every customer -- so its id and
 * secret are in config; what is per customer are the tokens, kept on the
 * connection.
 */
interface ConnectsWithOAuth
{
    /** Whether MajorLabel's app for this system is registered here (id and secret in config). */
    public static function oauthReady(): bool;

    /** Where the person is sent to log in and allow Lavoro in. */
    public function authorizationUrl(string $redirect_uri, string $state): string;

    /**
     * Exchanges the code for tokens and stores them on the connection, with
     * whatever the system needs chosen next (an administration, a division).
     * Returns a sentence for the person, such as which administration it is.
     */
    public function completeAuthorization(string $code, string $redirect_uri): string;
}
