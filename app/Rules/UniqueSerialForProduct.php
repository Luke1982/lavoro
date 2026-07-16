<?php

namespace App\Rules;

use App\Models\Asset;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A serial number identifies one physical machine, so it may occur at most once per
 * product — the same serial on two different products is fine and does happen.
 * Every path that registers or renames a machine validates through this rule, so the
 * constraint and its message live in one place.
 *
 * Bundles carry no serial of their own, so callers skip the rule entirely there.
 * A null product means there is nothing to be unique within and the rule passes —
 * the accompanying `exists` rule reports that failure instead.
 *
 *   'serial_number' => ['required', 'string', 'max:255', UniqueSerialForProduct::forProduct($id)],
 *   'serial_number' => ['required', 'string', 'max:255', UniqueSerialForProduct::forProduct($id)->ignoring($asset)],
 *
 * For a request that posts several machines at once, `fromRow` reads the product from
 * the row under validation and also rejects a serial repeated across rows in the same
 * request, which a database check alone would let through:
 *
 *   'assets.*.serial_number' => ['required', 'string', 'max:255', UniqueSerialForProduct::fromRow()],
 */
class UniqueSerialForProduct implements DataAwareRule, ValidationRule
{
    private const MESSAGE = 'Er bestaat al een machine met dit serienummer voor dit product.';

    private array $data = [];

    private ?int $ignore_asset_id = null;

    private function __construct(
        private readonly ?int $product_id,
        private readonly ?string $product_key,
    ) {}

    public static function forProduct(int|string|null $product_id): self
    {
        return new self($product_id !== null ? (int) $product_id : null, null);
    }

    public static function fromRow(string $product_key = 'product_id'): self
    {
        return new self(null, $product_key);
    }

    public function ignoring(Asset|int|null $asset): self
    {
        $this->ignore_asset_id = $asset instanceof Asset ? $asset->id : $asset;

        return $this;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $serial = trim($value);
        $product_id = $this->productIdFor($attribute);

        if ($product_id === null) {
            return;
        }

        $exists = Asset::where('product_id', $product_id)
            ->where('serial_number', $serial)
            ->when($this->ignore_asset_id, fn ($query) => $query->whereKeyNot($this->ignore_asset_id))
            ->exists();

        if ($exists || $this->repeatedInEarlierRow($attribute, $serial, $product_id)) {
            $fail(self::MESSAGE);
        }
    }

    private function productIdFor(string $attribute): ?int
    {
        if ($this->product_key === null) {
            return $this->product_id;
        }

        $product_id = data_get($this->data, $this->rowPrefix($attribute) . '.' . $this->product_key);

        return $product_id !== null ? (int) $product_id : null;
    }

    /**
     * Only earlier rows count as a clash: checking every other row would fail both
     * halves of a duplicate pair and report the same problem twice.
     */
    private function repeatedInEarlierRow(string $attribute, string $serial, int $product_id): bool
    {
        if ($this->product_key === null) {
            return false;
        }

        $row_prefix = $this->rowPrefix($attribute);
        $rows = data_get($this->data, Str::beforeLast($row_prefix, '.'), []);
        $current_index = (int) Str::afterLast($row_prefix, '.');
        $field = Str::afterLast($attribute, '.');

        foreach ($rows as $index => $row) {
            if ((int) $index >= $current_index) {
                continue;
            }

            $row_product_id = data_get($row, $this->product_key);
            $row_serial = data_get($row, $field);

            if ($row_product_id !== null
                && (int) $row_product_id === $product_id
                && is_string($row_serial)
                && trim($row_serial) === $serial) {
                return true;
            }
        }

        return false;
    }

    private function rowPrefix(string $attribute): string
    {
        return Str::beforeLast($attribute, '.');
    }
}
