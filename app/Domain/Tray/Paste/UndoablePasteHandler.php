<?php

namespace App\Domain\Tray\Paste;

use App\Models\User;

/**
 * A paste that can be taken back from the message it leaves behind.
 */
interface UndoablePasteHandler extends PasteHandler
{
    /**
     * @param  array<string, mixed>  $undo  What the PasteResult handed out to undo it.
     * @return string The sentence shown once it has been taken back.
     */
    public function undo(User $user, array $undo): string;
}
