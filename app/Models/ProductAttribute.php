<?php

namespace App\Models;

use App\Domain\Products\AttributeNumber;
use App\Enums\ProductAttributeValueTypes;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A kenmerk: a property products are told apart by, with a list of values to
 * pick from. A numeric one keeps the number of each value beside its label
 * ("1,5 kW"), so it can be compared and filtered on.
 */
class ProductAttribute extends Model
{
    use HasActivities;
    use HasFactory;
    use RecordsHistory;

    protected $fillable = ['name', 'searchable', 'value_type', 'unit'];

    protected $casts = [
        'searchable' => 'boolean',
    ];

    protected $attributes = [
        'value_type' => 'text',
    ];

    protected array $activity_labels = [
        'name' => 'Naam',
        'searchable' => 'Zoekbaar',
    ];

    /** Reported by ProductAttributeFormatChanged. */
    protected array $activity_ignore = ['value_type', 'unit'];

    protected static function booted(): void
    {
        static::deleting(function (ProductAttribute $attribute): void {
            $attribute->productTypes()->detach();
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)
            ->chaperone('productAttribute')
            ->orderBy('number')
            ->orderBy('value');
    }

    public function productTypes(): MorphToMany
    {
        return $this->morphToMany(ProductType::class, 'producttypeable');
    }

    public function isNumeric(): bool
    {
        return $this->value_type === ProductAttributeValueTypes::number->name;
    }

    /**
     * The columns of a value as typed by a person, or null when this kenmerk
     * is numeric and the input is not a number.
     *
     * @return array{value: string, number: ?float}|null
     */
    public function valueFields(string $input): ?array
    {
        if (!$this->isNumeric()) {
            return ['value' => trim($input), 'number' => null];
        }

        $number = AttributeNumber::parse($input, $this->unit);

        return $number === null ? null : $this->numberFields($number);
    }

    /** @return array{value: string, number: float} the number and the label written from it */
    public function numberFields(float $number): array
    {
        return ['value' => AttributeNumber::label($number, $this->unit), 'number' => $number];
    }
}
