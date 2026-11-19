<?php

namespace App\Domain\Integrations;

use App\Exceptions\Refusal;

/**
 * The other side does not have this record (any more): a 404. A connector's
 * fetch turns it into null, which the synchronizer reads as "removed there".
 */
class RecordNotFound extends Refusal {}
