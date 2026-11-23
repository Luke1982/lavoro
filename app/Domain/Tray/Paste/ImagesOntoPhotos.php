<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Images\CopyImagesAction;
use App\Actions\Images\RemoveImageAction;
use App\Domain\Tray\Kinds\ImageKind;
use App\Exceptions\Refusal;
use App\Models\Event;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Photos dropped among the photos of a record: copies of them go there, on the
 * side the drop names, the way uploading them there by hand would. Copying the
 * internal photos of a werkbon to the ones the customer sees is the same paste
 * onto the same werkbon. A photo already lying on that side is left alone.
 *
 * Only copying, so it happens at once and can be undone by whoever may also
 * delete those copies by hand.
 */
class ImagesOntoPhotos implements UndoablePasteHandler
{
    public function question(Paste $paste): ?Question
    {
        $this->newHere($paste, $this->target($paste));

        return null;
    }

    public function perform(Paste $paste): PasteResult
    {
        $record = $this->target($paste);
        $internal = $this->internal($paste);
        $new = $this->newHere($paste, $record);
        $copies = app(CopyImagesAction::class)->execute($record, $new, $internal);

        $message = ImageKind::heading(count($copies)) . (count($copies) === 1 ? ' staat' : ' staan')
            . ' nu bij de ' . ($internal ? 'interne ' : '') . "foto's van " . ImageKind::recordLabel($record) . '.'
            . ImageKind::leftAlone($paste->records->diff($new), 'stond er al', 'stonden er al');

        $undo = $this->mayRemove($paste->user, $record, $copies[0]) ? [
            'image_ids' => array_map(fn (Image $copy) => $copy->id, $copies),
            'imageable_type' => $record->getMorphClass(),
            'imageable_id' => $record->getKey(),
        ] : null;

        return new PasteResult($message, undo: $undo, created: [
            'images' => array_map(fn (Image $copy) => $copy->fresh()->toArray(), $copies),
        ]);
    }

    /** Removes the copies again, as far as they still hang off the record they were pasted on. */
    public function undo(User $user, array $undo): string
    {
        $record = $this->record($user, $undo['imageable_type'], $undo['imageable_id']);
        $copies = Image::whereIn('id', $undo['image_ids'])
            ->get()
            ->filter(fn (Image $copy) => $copy->isAttachedTo($record::class, $record->getKey()));

        foreach ($copies as $copy) {
            if (!$this->mayRemove($user, $record, $copy)) {
                throw new Refusal("Je mag deze foto's niet verwijderen.");
            }

            app(RemoveImageAction::class)->execute($copy);
        }

        return ($copies->count() === 1 ? 'De foto is' : "De foto's zijn") . ' daar weer weg.';
    }

    /** The record dropped on, as long as this person may add photos to it by hand. */
    private function target(Paste $paste): Model
    {
        $record = $this->record($paste->user, $paste->target['record'], $paste->target['id']);
        $allowed = $record instanceof Event
            ? Gate::forUser($paste->user)->allows('provideFeedback', $record)
            : Gate::forUser($paste->user)->allows('create', Image::class);

        if (!$allowed) {
            throw new Refusal("Je mag hier geen foto's toevoegen.");
        }

        return $record;
    }

    /**
     * The record, as long as photos can hang off it and this person may reach it:
     * among the records whose photos they may see, and allowed by its policy when
     * that says who may view it.
     */
    private function record(User $user, string $type, int $id): Model
    {
        $class = Image::attachableType($type) ?? throw new Refusal("Hier kunnen geen foto's bij.");
        $record = ImageKind::recordsVisibleTo($class, $user)->find($id);
        $policy = $record === null ? null : Gate::getPolicyFor($record);
        $denied = $policy !== null && method_exists($policy, 'view') && Gate::forUser($user)->denies('view', $record);

        if ($record === null || $denied) {
            throw new Refusal('Dit kun je niet openen.');
        }

        return $record;
    }

    private function internal(Paste $paste): bool
    {
        return (bool) ($paste->target['internal'] ?? false);
    }

    /** The photos that do not lie on this side of this record yet; none at all is a refusal. */
    private function newHere(Paste $paste, Model $record): Collection
    {
        $here = DB::table('imageables')
            ->whereIn('image_id', $paste->records->modelKeys())
            ->where('imageable_type', $record->getMorphClass())
            ->where('imageable_id', $record->getKey())
            ->where('internal', $this->internal($paste))
            ->pluck('image_id');

        $new = $paste->records->reject(fn (Image $image) => $here->contains($image->id));

        if ($new->isEmpty()) {
            throw new Refusal($paste->these('staat', 'staan') . ' hier al.');
        }

        return $new;
    }

    /** Photos with an appointment are feedback on it, and follow its rights rather than the photo rights. */
    private function mayRemove(User $user, Model $record, Image $copy): bool
    {
        return $record instanceof Event
            ? Gate::forUser($user)->allows('provideFeedback', $record)
            : Gate::forUser($user)->allows('delete', $copy);
    }
}
