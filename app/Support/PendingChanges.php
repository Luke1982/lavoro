<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The fields of a model that are about to change, each with its value before
 * and after, read before saving: what a signal reports when it, and not the
 * generic model trail, tells the story of a change.
 */
final class PendingChanges
{
    /**
     * @param  array<int, string>|null  $only  limit to these fields
     * @return array<string, array{0: mixed, 1: mixed}> field => [before, after]
     */
    public static function of(Model $model, ?array $only = null): array
    {
        $fields = array_keys($model->getDirty());

        if ($only !== null) {
            $fields = array_values(array_intersect($fields, $only));
        }

        return array_combine(
            $fields,
            array_map(fn (string $field) => [$model->getOriginal($field), $model->getAttribute($field)], $fields),
        );
    }
}
