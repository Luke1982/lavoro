<?php

namespace App\Http\Requests;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The product list and its filters. A range is given per numeric kenmerk as
 * range[kenmerk id][min] and [max], either end open, both ends included; a
 * decimal comma is read as a point.
 */
class ProductIndexRequest extends FormRequest
{
    private const ID_LIST = 'regex:/^\d+(,\d+)*$/';

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $range = $this->input('range');

        if (!is_array($range)) {
            return;
        }

        array_walk_recursive($range, function (&$bound) {
            $bound = is_string($bound) ? str_replace(',', '.', trim($bound)) : $bound;
        });

        $this->merge(['range' => $range]);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyType' => ['sometimes', 'nullable', self::ID_LIST],
            'onlyBrand' => ['sometimes', 'nullable', self::ID_LIST],
            'onlyAttributeValues' => ['sometimes', 'nullable', self::ID_LIST],
            'range' => ['sometimes', 'bail', 'array', function (string $field, mixed $value, Closure $fail) {
                if (array_filter(array_keys($value), fn ($key) => !ctype_digit((string) $key)) !== []) {
                    $fail('Een bereik hoort bij een kenmerk.');
                }
            }],
            'range.*' => ['array:min,max'],
            'range.*.min' => ['nullable', 'numeric'],
            'range.*.max' => ['nullable', 'numeric'],
        ];
    }

    public function attributes(): array
    {
        return [
            'range.*.min' => 'vanaf',
            'range.*.max' => 'tot en met',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->ranges() as $attribute_id => ['min' => $min, 'max' => $max]) {
                if ($min !== null && $max !== null && $min > $max) {
                    $validator->errors()->add(
                        'range.' . $attribute_id . '.max',
                        'Tot en met kan niet kleiner zijn dan vanaf.',
                    );
                }
            }
        }];
    }

    /** @return array<int, int> */
    public function idList(string $key): array
    {
        return array_map('intval', array_filter(explode(',', (string) $this->validated($key)), 'strlen'));
    }

    /**
     * Read from the input rather than the validated data, because after() asks
     * too; a bound that is not a number counts as open.
     *
     * @return array<int, array{min: ?float, max: ?float}> kenmerk id → range, only those with an end
     */
    public function ranges(): array
    {
        $ranges = [];

        foreach ((array) $this->input('range', []) as $attribute_id => $bounds) {
            $range = [
                'min' => self::bound($bounds['min'] ?? null),
                'max' => self::bound($bounds['max'] ?? null),
            ];

            if ($range['min'] !== null || $range['max'] !== null) {
                $ranges[(int) $attribute_id] = $range;
            }
        }

        return $ranges;
    }

    private static function bound(mixed $bound): ?float
    {
        return is_numeric($bound) ? (float) $bound : null;
    }
}
