<?php

namespace App\Domain\Integrations\Data;

use App\Models\Material;

/**
 * A material as every connector speaks it; in a bookkeeping system this is an
 * article. Prices are excluding VAT.
 */
class MaterialData extends RecordData
{
    public const FIELDS = ['name', 'code', 'price', 'cost_price', 'stock'];

    public ?string $code = null;

    public ?float $price = null;

    public ?float $cost_price = null;

    public ?float $stock = null;

    public static function fields(): array
    {
        return self::FIELDS;
    }

    public static function fromMaterial(Material $material): self
    {
        return self::from([...$material->only(self::FIELDS), 'reference' => (string) $material->getKey()]);
    }

    protected function clean(string $field, mixed $value): mixed
    {
        if (in_array($field, ['price', 'cost_price', 'stock'], true)) {
            return is_numeric($value) ? (float) $value : null;
        }

        return parent::clean($field, $value);
    }
}
