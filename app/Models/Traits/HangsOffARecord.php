<?php

namespace App\Models\Traits;

use App\Domain\Assistant\Reading\RecordKinds;
use App\Models\Event;
use App\Models\ServiceCheckInstance;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\AttachableType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * For an attachment that hangs off one record through a morph pivot of its own:
 * an Image through imageables.imageable_*, a Document through
 * documentables.documentable_*, a Remark through remarkables.remarkable_*. The
 * record is read from that link, never from whatever record a client says it is
 * looking at.
 *
 * The class lists the records it can hang off in ATTACHABLE_TO. A request names
 * its record by class and the controller builds that class, so nothing outside
 * that list may get that far.
 *
 * @mixin Model
 */
trait HangsOffARecord
{
    /** The record type a request names, if this can hang off it; the pages send it with a leading backslash. */
    public static function attachableType(mixed $type): ?string
    {
        return AttachableType::resolve($type, static::ATTACHABLE_TO);
    }

    /**
     * The ones this person may read: with the right to read this kind at all, and
     * hanging off a record they may read -- of a kind they may read, and among
     * that kind's own visibleTo() where it has one. One with more than one link
     * counts when any of them does.
     */
    public function scopeVisibleTo(EloquentBuilder $query, ?User $user): EloquentBuilder
    {
        if (!$user || $user->cannot('viewAny', static::class)) {
            return $query->whereRaw('1 = 0');
        }

        $readable = array_filter(static::ATTACHABLE_TO, fn (string $class) => $user->can('viewAny', $class));

        if ($readable === []) {
            return $query->whereRaw('1 = 0');
        }

        $morph = static::attachmentMorph();
        $pivot = $morph . 's';

        return $query->where(function (EloquentBuilder $query) use ($readable, $user, $morph, $pivot) {
            foreach ($readable as $class) {
                $record = new $class;

                $query->orWhereExists(fn (Builder $links) => $links->from($pivot)
                    ->whereColumn($pivot . '.' . $this->getForeignKey(), $this->getQualifiedKeyName())
                    ->where($pivot . '.' . $morph . '_type', $record->getMorphClass())
                    ->when(
                        RecordKinds::hasVisibilityScope($class),
                        fn (Builder $links) => $links->whereIn(
                            $pivot . '.' . $morph . '_id',
                            static::readableRecordsOf($class, $user)->select($record->getQualifiedKeyName())
                        )
                    ));
            }

            static::alsoVisibleTo($query, $user);
        });
    }

    /**
     * The records of this class that what hangs off them may be read on: of a kind
     * this person may read, among that kind's own visibleTo() where it has one.
     * The same rule scopeVisibleTo() applies per link, for whoever starts from the
     * record instead.
     *
     * @param  class-string<Model>  $class
     */
    public static function readableRecordsOf(string $class, User $user): EloquentBuilder
    {
        if ($user->cannot('viewAny', $class)) {
            return $class::query()->whereRaw('1 = 0');
        }

        return RecordKinds::hasVisibilityScope($class) ? $class::visibleTo($user) : $class::query();
    }

    /**
     * Where this kind can be read other than on the record it hangs off, as further
     * orWhere()s on the query: a photo shared from a project shows on its werkbonnen.
     * Only asked once viewAny has allowed this kind at all.
     */
    protected static function alsoVisibleTo(EloquentBuilder $query, User $user): void {}

    /**
     * Whether this person may put one of these on this record: whoever may see it,
     * on an appointment whoever may see it and give feedback on it, on a storing
     * whoever may also open its page, and on a keurpunt only whoever may fill in
     * its keuring -- one they may only read stays as it is.
     */
    public static function mayPlaceOn(User $user, Model $record): bool
    {
        return match (true) {
            $record instanceof Event => $user->can('provideFeedback', $record) && $user->can('view', $record),
            $record instanceof ServiceCheckInstance => $record->serviceJob !== null && $user->can('update', $record->serviceJob),
            $record instanceof Ticket => $user->can('view', $record) && $user->can('viewPage', $record),
            default => $user->can('view', $record),
        };
    }

    /**
     * Whether this person may change or take away this one where it hangs: not on
     * a keurpunt of a keuring they may only read, whatever their rights on photos
     * or remarks, by the same rule as putting one there (mayPlaceOn).
     */
    public function keurpuntenAllowChangesBy(User $user): bool
    {
        $morph = $this->attachmentMorph();
        $keurpunt_ids = $this->attachmentLinks()
            ->where($morph . '_type', (new ServiceCheckInstance)->getMorphClass())
            ->pluck($morph . '_id');

        return ServiceCheckInstance::with('serviceJob')
            ->whereKey($keurpunt_ids)
            ->get()
            ->every(fn (ServiceCheckInstance $keurpunt) => static::mayPlaceOn($user, $keurpunt));
    }

    public function isAttachedTo(string $record_class, int $record_id): bool
    {
        $morph = $this->attachmentMorph();

        return $this->attachmentLinks()
            ->where($morph . '_type', (new $record_class)->getMorphClass())
            ->where($morph . '_id', $record_id)
            ->exists();
    }

    /** The record this hangs off, if it still exists. */
    public function attachedRecord(): ?Model
    {
        $morph = $this->attachmentMorph();
        $link = $this->attachmentLinks()->first();
        $model_class = ltrim((string) $link?->{$morph . '_type'}, '\\');

        if ($link === null || !is_subclass_of($model_class, Model::class)) {
            return null;
        }

        return $model_class::find($link->{$morph . '_id'});
    }

    /** The morph name of the pivot, which is also the table's name without its "s". */
    public static function attachmentMorph(): string
    {
        return Str::snake(class_basename(static::class)) . 'able';
    }

    private function attachmentLinks(): Builder
    {
        return DB::table($this->attachmentMorph() . 's')->where($this->getForeignKey(), $this->getKey());
    }
}
