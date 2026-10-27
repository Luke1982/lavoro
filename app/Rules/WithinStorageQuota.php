<?php

namespace App\Rules;

use App\Services\StorageQuota;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class WithinStorageQuota implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $files = is_array($value) ? $value : [$value];

        $incoming = collect($files)
            ->filter(fn ($f) => $f instanceof UploadedFile)
            ->sum(fn (UploadedFile $f) => $f->getSize());

        if (! (new StorageQuota)->hasRoomFor((int) $incoming)) {
            $fail('Uw opslaglimiet is bereikt. Neem contact op om uit te breiden.');
        }
    }
}
