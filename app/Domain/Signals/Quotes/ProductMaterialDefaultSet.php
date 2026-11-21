<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ChangeSentence;
use App\Enums\DefaultMaterialPrompts;
use App\Models\Product;
use App\Models\ProductMaterialDefault;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A material was set to come along with a product on quotes, or how it comes
 * along changed: the quantity, or whether it is added, asked or suggested.
 */
class ProductMaterialDefaultSet extends BaseSignal
{
    private const LABELS = ['quantity' => 'Aantal per stuk', 'prompt' => 'Meenemen'];

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $fields  field => [before, after]; before is null for a new one
     */
    public function __construct(
        public Product $product,
        public ProductMaterialDefault $default,
        public bool $is_new,
        public array $fields,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'product.material_default_set';
    }

    public static function label(): string
    {
        return 'Standaardmateriaal ingesteld';
    }

    public function subject(): Model
    {
        return $this->product;
    }

    public function activityCategory(): string
    {
        return $this->is_new ? 'created' : 'updated';
    }

    public function activityDescription(): ?string
    {
        $product = $this->product->display_name ?? 'product ' . $this->product->id;

        return $this->is_new
            ? 'Standaardmateriaal toegevoegd aan ' . $product . ': ' . $this->default->material?->name
                . ' (' . Quantity::human($this->default->quantity) . ' per stuk, '
                . mb_strtolower((string) $this->default->prompt?->label()) . ')'
            : 'Standaardmateriaal bij ' . $product . ': ' . ChangeSentence::of($this->changes());
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->fields as $field => [$before, $after]) {
            $changes[] = [
                'field' => $field,
                'label' => (self::LABELS[$field] ?? $field) . ' (' . $this->default->material?->name . ')',
                'old_value' => $before instanceof DefaultMaterialPrompts ? $before->value : $before,
                'new_value' => $after instanceof DefaultMaterialPrompts ? $after->value : $after,
                'old_label' => self::readable($before),
                'new_label' => self::readable($after),
            ];
        }

        return $changes;
    }

    private static function readable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DefaultMaterialPrompts => $value->label(),
            default => Quantity::human($value),
        };
    }
}
