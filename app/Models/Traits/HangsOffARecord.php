<?php

namespace App\Models\Traits;

use App\Rules\AttachableType;
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
