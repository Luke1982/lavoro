<?php

namespace App\Support;

use App\Models\Asset;

/**
 * The words people put in to get on with the job, and what counts as one.
 *
 * A monteur on a roof books a machine against a serial that says "onbekend"
 * and fills it in later; the assistant does the same for a machine that is
 * sold but not yet hung. Both have to be found again by the same search, so
 * the words live here once rather than in each place that writes or reads them.
 */
final class Placeholder
{
    /** What people actually type when they mean "I will sort this out later". */
    public const MARKERS = ['onbekend', 'unknown', 'onbekende', 'nnb', 'n.v.t.', 'tbd'];

    /**
     * Whether a value may be one, for finding records that want attention.
     *
     * Generous on purpose — "onbekend unit 2" and an empty field both turn up —
     * which is right for a list somebody reads through, and wrong for anything
     * that decides on its own: see isOne().
     */
    public static function looksLikeOne(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains(mb_strtolower($value), $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a serial is one outright: a marker on its own, or one handed out
     * by serialFor(). Strict, because this decides by itself — a real serial
     * such as 2NNB3401 must never be taken for a gap and thrown away.
     */
    public static function isOne(?string $serial): bool
    {
        return in_array(mb_strtolower(trim((string) $serial)), self::MARKERS, true) || self::wasHandedOut($serial);
    }

    /**
     * Whether serialFor() handed this serial out: a machine registered before it
     * was hung, still waiting for the mechanic. Narrower than isOne() on purpose.
     * A mechanic who types "onbekend" chose to carry on without one, and his taak
     * must stay completable; only the number the assistant put there holds it open.
     */
    public static function wasHandedOut(?string $serial): bool
    {
        return preg_match('/^onbekend-\d+$/i', trim((string) $serial)) === 1;
    }

    /**
     * A placeholder serial no other machine of this product carries.
     *
     * Numbered, because a serial is unique per product: two units of the same
     * airco both called "onbekend" is a refusal from the database on the second.
     *
     * @param  array<int, string>  $also_taken  Handed out earlier in the same batch, not yet saved.
     */
    public static function serialFor(int $product_id, array $also_taken = []): string
    {
        $taken = Asset::query()
            ->where('product_id', $product_id)
            ->where('serial_number', 'like', 'onbekend-%')
            ->pluck('serial_number')
            ->merge($also_taken)
            ->flip();

        $number = 1;

        while ($taken->has('onbekend-' . $number)) {
            $number++;
        }

        return 'onbekend-' . $number;
    }
}
