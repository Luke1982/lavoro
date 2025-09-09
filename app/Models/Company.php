<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'is_main'
    ];

    protected $casts = [
        'is_main' => 'boolean'
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

    /**
     * Returns logo data URI, inline style constraints and spacer height (mm) for PDF usage.
     * @return array{data:?string,style:string,spacer:int}
     */
    public static function pdfLogo(?Company $company = null): array
    {
        $company = $company ?: static::where('is_main', true)->first();
        if (!$company || !$company->logo_path) {
            return ['data' => null, 'style' => '', 'spacer' => 0];
        }
        $path = storage_path('app/public/' . $company->logo_path);
        if (!is_file($path) || filesize($path) === 0) {
            return ['data' => null, 'style' => '', 'spacer' => 0];
        }
        try {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isSvg = $ext === 'svg';
            $mime = $isSvg ? 'image/svg+xml' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            $data = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
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
        } catch (\Throwable $e) {
            return ['data' => null, 'style' => '', 'spacer' => 0];
        }
    }
}
