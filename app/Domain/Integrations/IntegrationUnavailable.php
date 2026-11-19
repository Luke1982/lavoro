<?php

namespace App\Domain\Integrations;

use App\Exceptions\Refusal;

/**
 * The other side could not be reached or asked us to wait: a network error, a
 * 429 or a 5xx. A refusal like any other for whoever pressed a button, but a
 * queued job tries again later instead of giving up, because nothing is wrong
 * with what was sent.
 */
class IntegrationUnavailable extends Refusal {}
