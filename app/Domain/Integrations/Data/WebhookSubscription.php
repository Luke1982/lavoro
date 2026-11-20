<?php

namespace App\Domain\Integrations\Data;

use Carbon\CarbonImmutable;

/**
 * A webhook the other side agreed to call: its id there, when it stops unless
 * it is renewed, and the secret it signs its notices with when it hands one out.
 */
class WebhookSubscription
{
    public function __construct(
        public ?string $id,
        public ?CarbonImmutable $expires_at,
        public ?string $signing_secret = null,
    ) {}
}
