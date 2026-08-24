<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Productable;
use App\Rules\UniqueSerialForProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssetStoreRequest extends FormRequest
{
    /** @var Collection<int, Productable>|null */
    private ?Collection $productables = null;

    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return $user->hasPermission('asset.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number') && $this->input('serial_number') === '') {
            $this->merge(['serial_number' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serienummer is verplicht.',
            'child_assets.*.serial_number.required' => 'Serienummer is verplicht.',
        ];
    }

    public function rules(): array
    {
        $product = Product::find($this->input('product_id'));

        $serial_rules = $product && !$product->requiresSerial()
            ? ['nullable', 'string', 'max:255']
            : [
                'required',
                'string',
                'max:255',
                UniqueSerialForProduct::forProduct($this->input('product_id')),
            ];

        return array_merge([
            'product_id' => ['required', 'exists:products,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
            'serial_number' => $serial_rules,
            'is_active' => ['nullable', 'boolean'],
            'next_service_date' => ['nullable', 'date'],
            'date_in_service' => ['nullable', 'date'],
            'child_assets' => ['nullable', 'array'],
            'child_assets.*.productable_id' => ['required_with:child_assets', 'integer', 'exists:productables,id'],
        ], $this->childSerialRules());
    }

    /**
     * A part only needs a serienummer when its own product carries one, which is a property
     * of the part rather than of the bundle it sits in: a zonnepaneel-bundel registers its
     * omvormer by serial and counts its panelen. Spelled out per row rather than through a
     * wildcard, because an empty field reaches here as null and a `nullable` wildcard would
     * wave it through for every part alike.
     *
     * @return array<string, array<int, mixed>>
     */
    private function childSerialRules(): array
    {
        $productables = $this->productables();
        $rules = [];

        foreach ($this->childRows() as $index => $row) {
            $requires_serial = $productables->get($row['productable_id'] ?? null)
                ?->childProduct?->requiresSerial() ?? true;

            $rules["child_assets.{$index}.serial_number"] = [
                $requires_serial ? 'required' : 'nullable',
                'string',
                'max:255',
            ];
        }

        return $rules;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function childRows(): array
    {
        $rows = $this->input('child_assets');

        return is_array($rows) ? $rows : [];
    }

    /**
     * The catalogue rows the posted parts point at, keyed by id and resolved once for both
     * the per-row rules and the checks after them. Only rows describing a part of a product
     * are in reach: a werkbon taak keeps its chosen aantallen in the same table.
     *
     * @return Collection<int, Productable>
     */
    private function productables(): Collection
    {
        return $this->productables ??= Productable::with('childProduct')
            ->where('productable_type', Product::class)
            ->whereIn('id', collect($this->childRows())->pluck('productable_id')->filter())
            ->get()
            ->keyBy('id');
    }

    /**
     * Parts are addressed by the bundle's own catalogue row, so a row belonging to another
     * product would silently hang a part under a machine it does not belong to. A part with
     * a fixed aantal is capped at that number; a flex part settles its number here, so it
     * has no ceiling to run into.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $product_id = (int) $this->input('product_id');
                $rows = collect($this->childRows());
                $counts = $rows->countBy('productable_id');
                $reported = [];

                foreach ($rows as $index => $row) {
                    $productable_id = $row['productable_id'] ?? null;

                    if (in_array($productable_id, $reported, true)) {
                        continue;
                    }

                    $productable = $this->productables()->get($productable_id);

                    if (!$productable || (int) $productable->product_id !== $product_id) {
                        $reported[] = $productable_id;
                        $validator->errors()->add(
                            "child_assets.{$index}.productable_id",
                            'Dit onderdeel hoort niet bij het gekozen product.'
                        );

                        continue;
                    }

                    if (!$productable->flex_quantity && $counts[$productable_id] > $productable->quantity) {
                        $reported[] = $productable_id;
                        $validator->errors()->add(
                            "child_assets.{$index}.productable_id",
                            'Het maximale aantal (' . $productable->quantity . ') voor dit onderdeel is overschreden.'
                        );
                    }
                }
            },
        ];
    }
}
