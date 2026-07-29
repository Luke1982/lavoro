<?php

namespace App\Domain\Tools;

/**
 * A tool that can say, in Dutch, what it is about to do.
 *
 * The button that carries an action out sits under a paragraph of the model's
 * prose, and by the time somebody has asked two more things that paragraph is
 * halfway up the box. A button labelled only "bevestigen" is then asking them to
 * agree to something they have to scroll to re-read — so it carries its own
 * description instead, written from the arguments that will actually be used.
 */
interface Confirmable
{
    public function previewOf(ToolCall $call): string;
}
