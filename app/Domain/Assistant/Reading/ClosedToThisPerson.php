<?php

namespace App\Domain\Assistant\Reading;

use App\Exceptions\Refusal;

/**
 * A condition refused because this person lacks the right to what it names: a
 * field behind a permission, or a relation to a kind they may not read. Every
 * other refusal means the condition does not fit the records as they are,
 * which for a stored filter is a field that has since been dropped or renamed.
 */
final class ClosedToThisPerson extends Refusal {}
