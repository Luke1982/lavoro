<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Product;

/**
 * @method \App\Models\Product|null route(string $key = null)
 * @method mixed input(string $key = null, mixed $default = null)
 *
 * @property int    $product_type_id
 * @property int    $brand_id
 * @property string $model
 * @property string|null $description
 * @property string|null $start_sell
 * @property string|null $end_sell
 * @property string|null $origin
 * @property int|null $typical_certificate_days
 * @property string|null $retail_price
 * @property string|null $purchase_price
 */
class ProductStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId  = $this->route('product')?->id;
        $brandId    = $this->input('brand_id');
        $typeId     = $this->input('product_type_id');
        $modelName  = $this->input('model');
        $startSell  = $this->input('start_sell');
        $endSell    = $this->input('end_sell');

        return [
            'product_type_id' => ['required', 'exists:product_types,id'],
            'brand_id'        => ['required', 'exists:brands,id'],
            'model'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'start_sell'      => ['required', 'date'],
            'end_sell'        => [
                'required',
                'date',
                'after_or_equal:start_sell',
                function ($attribute, $value, $fail) use ($brandId, $typeId, $modelName, $productId, $startSell) {
                    $overlap = Product::query()
                        ->where('brand_id', $brandId)
                        ->where('product_type_id', $typeId)
                        ->where('model', $modelName)
                        ->when($productId, fn($q) => $q->where('id', '!=', $productId))
                        ->where('start_sell', '<=', $value)
                        ->where('end_sell', '>=', $startSell)
                        ->exists();

                    if ($overlap) {
                        $fail('Er bestaat al een product met deze combinatie van merk, producttype en model dat valt binnen de opgegeven verkoopdatums.');
                    }
                },
            ],
            'origin'       => [
                'nullable',
                'string',
            ],
            'typical_certificate_days' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'retail_price'   => 'nullable|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'model.unique'                     => 'Er bestaat al een product met deze combinatie van merk, producttype en model.',
            'end_sell.after_or_equal'          => 'De einddatum van verkoop moet gelijk zijn aan of na de startdatum van verkoop.',
            'product_type_id.required'         => 'Het producttype is verplicht.',
            'brand_id.required'                => 'Het merk is verplicht.',
            'model.required'                   => 'Het model is verplicht.',
            'model.string'                     => 'Het model moet een geldige tekenreeks zijn.',
            'model.max'                        => 'Het model mag niet langer zijn dan 255 tekens.',
            'description.string'               => 'De beschrijving moet een geldige tekenreeks zijn.',
            'start_sell.date'                  => 'De startdatum van verkoop moet een geldige datum zijn.',
            'end_sell.date'                    => 'De einddatum van verkoop moet een geldige datum zijn.',
            'start_sell.required'              => 'De startdatum van verkoop moet een geldige datum zijn.',
            'end_sell.required'                => 'De einddatum van verkoop moet een geldige datum zijn.',
        ];
    }
}
