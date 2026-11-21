<?php

namespace App\Listeners\Company;

use App\Domain\Signals\Company\CompanyLogoReplaced;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

/**
 * The file of a replaced logo is removed once the new one is saved for good.
 * Before the commit it would be gone while the company still pointed at it,
 * should the save fail.
 */
class DeleteReplacedCompanyLogo implements ShouldHandleEventsAfterCommit
{
    public function handle(CompanyLogoReplaced $signal): void
    {
        if ($signal->previous_path && $signal->previous_path !== $signal->new_path) {
            Storage::disk('public')->delete($signal->previous_path);
        }
    }
}
