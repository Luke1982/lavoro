<?php

namespace App\Domain\Integrations\Data;

use Carbon\CarbonImmutable;

/**
 * A webhook the other side agreed to call: its id there, and when it stops
 * unless it is renewed.
 */
class WebhookSubscription
{
    public function __construct(
        public ?string $id,
        public ?CarbonImmutable $expires_at,
    ) {}
}
