<?php

namespace App\Domain\Integrations\Data;

use App\Domain\Integrations\Area;

/**
 * "Something changed over here", as read from a webhook. Without an id the
 * other side did not say which record, and the whole area is pulled.
 */
class ChangeNotice
{
    public function __construct(
        public Area $area,
        public ?string $external_id = null,
    ) {}
}
