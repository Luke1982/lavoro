<?php

namespace Tests\Concerns;

use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * Every model that records history, found rather than listed: a list goes stale the
 * moment someone adds the trait to a model and forgets to add it to the guard too.
 */
trait FindsModelsWithHistory
{
    /** @return array<int, class-string<Model>> */
    protected function modelsWithHistory(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (class_exists($class) && in_array(RecordsHistory::class, class_uses_recursive($class), true)) {
                $classes[] = $class;
            }
        }

        $this->assertNotEmpty($classes, 'no model with history was found, so this guard proves nothing');

        return $classes;
    }
}
