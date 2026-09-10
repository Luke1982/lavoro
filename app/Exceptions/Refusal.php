<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * "This cannot be done, and this is why" -- meant for whoever pressed the
 * button.
 *
 * An ordinary RuntimeException becomes a 500, and the handling turns that into
 * "Er is een serverfout opgetreden". The real reason -- there is nothing to
 * invoice, that coupon is already used, that address is already in use -- is
 * lost then, while it had already been written down. Whoever throws this knows
 * the text may be read.
 *
 * Only for cases the user can solve themselves. A missing extension or a wrong
 * setting should stay a real error, stack trace and all.
 */
class Refusal extends RuntimeException {}
