<?php

namespace App\Models;

use App\Enums\ShareAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One record made visible from another: `shareable` is what is shared, `source`
 * the record it is shared from. Who sees it follows from the source — a
 * project's shares appear on every work order of that project.
 *
 * A pivot as well as a record of its own, so the receiving side gets the share
 * along with what was shared, with `access` read as a level it can ask about.
 *
 * @property string $shareable_type
 * @property int $shareable_id
 * @property string $source_type
 * @property int $source_id
 * @property ShareAccess $access
 * @property int|null $user_id
 */
class Share extends Pivot
{
    protected $table = 'shares';

    public $incrementing = true;

    protected $fillable = [
        'shareable_type',
        'shareable_id',
        'source_type',
        'source_id',
        'access',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'access' => ShareAccess::class,
        ];
    }

    /**
     * The records whose shares have somewhere to go: a project's go to its work
     * orders, a quote's to the customer, with the mail that sends it.
     */
    public static function canShareFrom(?Model $source): bool
    {
        return $source instanceof Project || $source instanceof Quote;
    }

    /** Where a record's shares go, as a person says it. */
    public static function audience(Model $source): string
    {
        return $source instanceof Quote ? 'de klant' : 'de werkbonnen';
    }

    /**
     * Whether this person may decide what goes from here, beyond their rights on
     * what is shared: what a quote sends along is part of the quote, so it is
     * theirs to change only while they may change the quote itself.
     */
    public static function mayShareFrom(User $user, ?Model $source): bool
    {
        return !$source instanceof Quote
            || ($user->can('update', $source) && $source->statusEnum()->allowsEditing());
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grants(ShareAccess $access): bool
    {
        return $this->access->allows($access);
    }
}
