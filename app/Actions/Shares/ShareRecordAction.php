<?php

namespace App\Actions\Shares;

use App\Domain\Signals\Attachments\ImageShareChanged;
use App\Domain\Signals\Signal;
use App\Domain\Signals\Signals;
use App\Enums\ShareAccess;
use App\Exceptions\Refusal;
use App\Models\Image;
use App\Models\Share;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Shares a record from where it lives, or stops sharing it, and puts that on the
 * source's timeline. Where it lives is read from the record itself, as a photo
 * reads it from its own link, never from what a screen says it is showing.
 *
 * Takes the state that is wanted rather than flipping it, so a second screen that
 * still shows the old state cannot undo what the first one just did. Asking for
 * the state a record is already in changes nothing and says nothing.
 */
class ShareRecordAction
{
    public function execute(Model $shareable, ?Model $source, bool $shared): void
    {
        throw_unless(
            Share::canShareFrom($source),
            Refusal::class,
            'Delen kan alleen vanaf een project, met de werkbonnen eronder.',
        );

        $signal = $this->signalFor($shareable, $source, $shared);

        $link = [
            'shareable_type' => $shareable->getMorphClass(),
            'shareable_id' => $shareable->getKey(),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ];

        $changed = $shared
            ? Share::createOrFirst($link, ['access' => ShareAccess::View, 'user_id' => Auth::id()])->wasRecentlyCreated
            : Share::where($link)->delete() > 0;

        if ($changed) {
            Signals::dispatch($signal);
        }
    }

    /**
     * Built before anything is written, so a kind of record without its own signal
     * fails before it can change anything the timeline would not show.
     */
    private function signalFor(Model $shareable, Model $source, bool $shared): Signal
    {
        return match (true) {
            $shareable instanceof Image => new ImageShareChanged($source, $shareable, $shared),
            default => throw new LogicException(class_basename($shareable) . ' has no signal for being shared.'),
        };
    }
}
