<?php

namespace App\Listeners\Company;

use App\Domain\Signals\ModelChanged;
use App\Models\Company;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

/** A company that is gone takes its logos along, once that is certain. */
class DeleteLogosOfRemovedCompany implements ShouldHandleEventsAfterCommit
{
    public function handle(ModelChanged $signal): void
    {
        if ($signal->action !== 'deleted' || !$signal->model instanceof Company) {
            return;
        }

        foreach (array_keys(Company::LOGOS) as $field) {
            if ($signal->model->{$field}) {
                Storage::disk('public')->delete($signal->model->{$field});
            }
        }
    }
}
