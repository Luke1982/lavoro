<?php

namespace App\Domain\Tools;

/**
 * A confirmable tool whose preview tells more than what is being agreed to.
 *
 * The approval is sealed with what the person was shown, and the click is
 * refused when that has changed since. For most tools the preview is exactly
 * the decision. Some add facts around it: booking three filters shows the
 * stock going from 10 to 7, and that is information, not the agreement. Any
 * filter taken from the shelf in between would then refuse a booking that
 * did not change at all.
 *
 * Such a tool says here which facts the person decided on, and only those are
 * sealed. Anything that would make the click do something else belongs in it.
 */
interface SealsWhatWasAgreed extends Confirmable
{
    public function sealOf(ToolCall $call): string;
}
