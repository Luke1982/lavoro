<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'logo_path',
        'logo_negative_path',
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            if ($company->is_main) {
                DB::transaction(function () use ($company) {
                    Company::where('is_main', true)
                        ->where('id', '!=', $company->id)
                        ->update(['is_main' => false]);
                });
            }
        });
    }

    public static function main(): ?self
    {
        return static::where('is_main', true)->first();
    }

    /**
     * The logo as a file on the tenant's disk, or null when there is nothing
     * to show. Never through storage_path(): that is the shared folder, which
     * under tenancy holds no customer's files.
     */
    public function logoFile(): ?string
    {
        $disk = Storage::disk('public');

        return $this->logo_path && $disk->exists($this->logo_path) && $disk->size($this->logo_path) > 0
            ? $disk->path($this->logo_path)
            : null;
    }

    /** The logo inline, for what cannot fetch it: a pdf, or a visitor without a login. */
    public function logoDataUri(): ?string
    {
        $path = $this->logoFile();

        if ($path === null) {
            return null;
        }

        $mime = match ($extension = strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'jpg' => 'image/jpeg',
            default => 'image/' . $extension,
        };

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    /**
     * Returns logo data URI, inline style constraints and spacer height (mm) for PDF usage.
     *
     * @return array{data:?string,style:string,spacer:int}
     */
    public static function pdfLogo(?Company $company = null): array
    {
        $company ??= static::main();
        $path = $company?->logoFile();
        if ($path === null) {
            return ['data' => null, 'style' => '', 'spacer' => 0];
        }
        try {
            $isSvg = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
            $data = $company->logoDataUri();
            $maxWidthMm = 52;
            $wideMaxHeight = 13; // ~66% of 20
            $tallMaxHeight = 18; // ~66% of 28
            $aspect = null;
            if (!$isSvg) {
                [$w, $h] = @getimagesize($path) ?: [null, null];
                if ($w && $h) {
                    $aspect = $w / max($h, 1);
                }
            }
            $maxHeightMm = ($aspect !== null && $aspect >= 2) ? $wideMaxHeight : $tallMaxHeight;
            $style = sprintf('max-width:%dmm; max-height:%dmm; width:auto; height:auto;', $maxWidthMm, $maxHeightMm);
            $spacer = $maxHeightMm + 8;

            return ['data' => $data, 'style' => $style, 'spacer' => $spacer];
        } catch (\Throwable) {
            return ['data' => null, 'style' => '', 'spacer' => 0];
        }
    }
}
