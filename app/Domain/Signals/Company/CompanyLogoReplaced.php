<?php

namespace App\Domain\Signals\Company;

use App\Domain\Signals\BaseSignal;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * A company got a new logo, or its first one. The paths are kept on the signal
 * for whoever cleans up the old file, and as the raw values in the trail; a
 * person reads that the logo was replaced, not where it is stored.
 */
class CompanyLogoReplaced extends BaseSignal
{
    public function __construct(
        public Company $company,
        public string $field,
        public ?string $previous_path,
        public ?string $new_path,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'company.logo_replaced';
    }

    public static function label(): string
    {
        return 'Logo vervangen';
    }

    public static function coveredFields(): array
    {
        return array_keys(Company::LOGOS);
    }

    public function subject(): Model
    {
        return $this->company;
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        return $this->logoName() . ($this->previous_path ? ' vervangen' : ' toegevoegd');
    }

    public function changes(): array
    {
        return [[
            'field' => $this->field,
            'label' => $this->logoName(),
            'old_value' => $this->previous_path,
            'new_value' => $this->new_path,
            'old_label' => $this->previous_path ? 'vorige afbeelding' : null,
            'new_label' => $this->new_path ? 'nieuwe afbeelding' : null,
        ]];
    }

    private function logoName(): string
    {
        return Company::LOGOS[$this->field] ?? 'Logo';
    }
}
