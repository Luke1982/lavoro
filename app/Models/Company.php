<?php

namespace App\Models;

use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasActivities;
    use HasFactory;
    use RecordsHistory;

    /** The logo columns and how a person calls them; CompanyLogoReplaced reports both. */
    public const LOGOS = [
        'logo_path' => 'Logo',
        'logo_negative_path' => 'Logo (negatief)',
    ];

    protected $fillable = [
        'name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'coc_number',
        'vat_number',
        'iban',
        'email',
        'phone',
        'website',
        'logo_path',
        'logo_negative_path',
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'address_line1' => 'Adres',
        'address_line2' => 'Adres (tweede regel)',
        'postal_code' => 'Postcode',
        'city' => 'Plaats',
        'country' => 'Land',
        'coc_number' => 'KvK-nummer',
        'vat_number' => 'Btw-nummer',
        'iban' => 'IBAN',
        'email' => 'E-mailadres',
        'phone' => 'Telefoon',
        'website' => 'Website',
        'is_main' => 'Hoofdbedrijf',
    ];

    /** A path on disk tells a person nothing; CompanyLogoReplaced says it in words. */
    protected array $activity_ignore = [
        'logo_path',
        'logo_negative_path',
    ];

    /**
     * There is one main company. The others step down one by one through
     * Eloquent, so each of them records that it is no longer the main company
     * instead of being flipped by a query nobody hears.
     */
    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            if (!$company->is_main) {
                return;
            }

            $others = Company::where('is_main', true)->where('id', '!=', $company->id)->get();

            foreach ($others as $other) {
                $other->update(['is_main' => false]);
            }
        });
    }

    public static function main(): ?self
    {
        return static::where('is_main', true)->first();
    }

    /**
     * A company that is removed has no timeline left; the main company is where
     * the assistant finds that it existed. Removing the main company itself
     * leaves no other that naturally holds it.
     *
     * @return array<int, Company|null>
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? [static::main()] : [];
    }

    public function historyLabel(): ?string
    {
        return 'Bedrijf "' . $this->name . '"';
    }

    /**
     * Who makes a quote, as it is printed on it: the address, the KvK number the
     * law requires, the VAT number and the bank account.
     *
     * @return array<string, ?string>
     */
    public function details(): array
    {
        return $this->only([
            'name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country',
            'coc_number', 'vat_number', 'iban', 'email', 'phone', 'website',
        ]);
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
