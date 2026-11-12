<?php

namespace App\Domain\Tools;

/**
 * A tool that can tell, before anybody is asked, that its proposal will fail.
 *
 * Everything a confirmable tool checks normally happens on the click, which
 * is the wrong moment for a mistake the model could have fixed itself: the
 * person agrees, and is then told the category did not exist. Checked here,
 * the refusal goes back to the model instead and no button is ever shown for
 * something that cannot happen.
 *
 * Only what can be known in advance belongs here. Anything that may change
 * between proposal and click — somebody else booking the slot — is still
 * checked again when the tool runs.
 */
interface ChecksBeforeConfirming
{
    /** A refusal to hand back instead of a confirmation button, or null when the proposal stands. */
    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult;
}
