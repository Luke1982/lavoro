<?php

namespace App\Domain\Tray\Paste;

/**
 * What happens when one kind of thing is dropped on one kind of place.
 *
 * A paste that makes something new, or that has more than one sensible outcome,
 * asks first: question() returns the choices, and perform() only ever sees an
 * answer that was among them. A paste that only links two records asks nothing,
 * happens at once and can be undone (see UndoablePasteHandler).
 */
interface PasteHandler
{
    public function question(Paste $paste): ?Question;

    public function perform(Paste $paste): PasteResult;
}
