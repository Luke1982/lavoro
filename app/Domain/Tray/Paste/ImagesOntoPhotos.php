<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Images\CopyImagesAction;
use App\Actions\Images\MoveImageAction;
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
 * Photos dropped among the photos of a record, on the side the drop names.
 * Copying the internal photos of a werkbon to the ones the customer sees is a
 * paste onto the same werkbon. A photo already lying on that side is left alone.
 *
 * Whoever may also read the photos where they are now and take them away there
 * is asked whether to copy them or to move them. A copy gets files of its own; a move takes the
 * photo itself along and leaves nothing behind. Anyone else gets copies, at once.
 * Either can be undone by whoever may remove them here by hand.
 */
class ImagesOntoPhotos implements UndoablePasteHandler
{
    private const COPY = 'copy';

    private const MOVE = 'move';

    public function question(Paste $paste): ?Question
    {
        $record = $this->target($paste);
        $new = $this->newHere($paste, $record);

        if (!$new->every(fn (Image $image) => $this->mayMove($paste->user, $image))) {
            return null;
        }

        $one = $new->count() === 1;
        $sources = $new->map(fn (Image $image) => Image::placeOf(ImageKind::source($image), ImageKind::internal($image)))
            ->unique();
        $there = $sources->count() === 1 ? 'bij ' . $sources->first() : 'waar ze nu staan';
        $shared = $new->contains(fn (Image $image) => $image->isSharedFrom(ImageKind::source($image)))
            ? ($one ? ' Hij staat dan ook' : ' Ze staan dan ook') . ' niet meer op de werkbonnen van het project.'
            : '';

        return new Question(
            title: ImageKind::heading($new->count()) . ' kopiëren of verplaatsen?',
            subtitle: 'Naar ' . Image::placeOf($record, $this->internal($paste)) . '.',
            items: ImageKind::listed($new),
            choices: [
                new Choice(
                    value: self::COPY,
                    label: 'Kopiëren',
                    description: ($one ? 'Hij blijft ook staan ' : 'Ze blijven ook staan ') . $there . '.',
                    confirm: 'Kopiëren',
                ),
                new Choice(
                    value: self::MOVE,
                    label: 'Verplaatsen',
                    description: 'Weg ' . $there . '.' . $shared . ($one ? ' De foto zelf blijft bestaan.' : " De foto's zelf blijven bestaan."),
                    confirm: 'Verplaatsen',
                ),
            ],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $record = $this->target($paste);
        $internal = $this->internal($paste);
        $new = $this->newHere($paste, $record);
        $moving = $paste->answer === self::MOVE;

        if ($moving) {
            foreach ($new as $image) {
                if (!$this->mayMove($paste->user, $image)) {
                    throw new Refusal(ImageKind::named($image) . ' mag je daar niet weghalen.');
                }
            }

            $origins = $new->map(fn (Image $image) => [
                'image_id' => $image->id,
                ...app(MoveImageAction::class)->execute($image, $record, $internal),
            ])->values()->all();
            $placed = $new->map->fresh()->all();
        } else {
            $placed = app(CopyImagesAction::class)->execute($record, $new, $internal);
        }

        $one = count($placed) === 1;
        $verb = match (true) {
            $moving && $one => ' is verplaatst naar ',
            $moving => ' zijn verplaatst naar ',
            $one => ' staat nu bij ',
            default => ' staan nu bij ',
        };
        $message = ImageKind::heading(count($placed)) . $verb . Image::placeOf($record, $internal) . '.'
            . ImageKind::leftAlone($paste->records->diff($new), 'stond er al', 'stonden er al');

        $undo = $this->mayRemove($paste->user, $record, $placed[0]) ? [
            'image_ids' => array_map(fn (Image $image) => $image->id, $placed),
            'imageable_type' => $record->getMorphClass(),
            'imageable_id' => $record->getKey(),
            ...($moving ? ['origins' => $origins] : []),
        ] : null;

        return new PasteResult($message, undo: $undo, created: [
            'images' => array_map(fn (Image $image) => $image->toArray(), $placed),
        ]);
    }

    /**
     * Takes back what is still here: copies are removed, moved photos go back to
     * where they were, on the same side, shared again when they were.
     */
    public function undo(User $user, array $undo): string
    {
        $record = $this->record($user, $undo['imageable_type'], $undo['imageable_id']);
        $origins = collect($undo['origins'] ?? [])->keyBy('image_id');
        $images = Image::whereIn('id', $undo['image_ids'])
            ->get()
            ->filter(fn (Image $image) => $image->isAttachedTo($record::class, $record->getKey()));

        foreach ($images as $image) {
            if (!$this->mayRemove($user, $record, $image)) {
                throw new Refusal("Je mag deze foto's hier niet weghalen.");
            }

            $origin = $origins->get($image->id);

            if ($origin === null) {
                app(RemoveImageAction::class)->execute($image);

                continue;
            }

            app(MoveImageAction::class)->execute(
                $image,
                $this->placeable($user, $origin['imageable_type'], $origin['imageable_id']),
                (bool) $origin['internal'],
                (bool) $origin['main'],
                (bool) $origin['shared'],
            );
        }

        $one = $images->count() === 1;

        return $origins->isEmpty()
            ? ($one ? 'De foto is' : "De foto's zijn") . ' daar weer weg.'
            : ($one ? 'De foto staat' : "De foto's staan") . ' weer waar ' . ($one ? 'hij stond' : 'ze stonden') . '.';
    }

    private function target(Paste $paste): Model
    {
        return $this->placeable($paste->user, $paste->target['record'], $paste->target['id']);
    }

    /** A record this person may add photos to by hand, as its own screen lets them (Image::mayUploadOn). */
    private function placeable(User $user, string $type, int $id): Model
    {
        $record = $this->record($user, $type, $id);

        if (!Image::mayUploadOn($user, $record)) {
            throw new Refusal("Je mag hier geen foto's toevoegen.");
        }

        return $record;
    }

    /**
     * The record, as long as photos can hang off it and this person may open it
     * by its policy, as its own screen does: also when they reach it only by being
     * on it, which is all its upload asks (mayPlaceOn holds view in every case).
     */
    private function record(User $user, string $type, int $id): Model
    {
        $class = Image::attachableType($type) ?? throw new Refusal("Hier kunnen geen foto's bij.");
        $record = $class::find($id);

        if ($record === null || Gate::forUser($user)->denies('view', $record)) {
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

    /**
     * Moving a photo takes it away where it is now, which takes a record this
     * person may read the photos of (Image::readableRecordsOf) and the right to
     * remove it there. A photo reached only through a werkbon it is shared on is
     * copied: moving it would take it off the project, and every werkbon with it.
     */
    private function mayMove(User $user, Image $image): bool
    {
        $source = ImageKind::source($image);

        return $source !== null
            && Image::readableRecordsOf($source::class, $user)->whereKey($source->getKey())->exists()
            && $this->mayRemove($user, $source, $image);
    }

    /** Photos with an appointment are feedback on it, and follow its rule (mayPlaceOn) rather than the photo rights. */
    private function mayRemove(User $user, Model $record, Image $image): bool
    {
        return $record instanceof Event
            ? Image::mayPlaceOn($user, $record)
            : Gate::forUser($user)->allows('delete', $image);
    }
}
