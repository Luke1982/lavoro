<?php

namespace App\Http\Requests;

use App\Rules\UniqueSerialForProduct;
use App\Services\TaskInstanceSerialSlotService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderTaskInstanceAssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin()
            || $user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return [
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'assets.*.serial_number' => [
                'required',
                'string',
                'max:255',
                UniqueSerialForProduct::fromRow(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'assets.*.serial_number.required' => 'Serienummer is verplicht.',
        ];
    }

    /**
     * Machines may only be registered against the products the task actually expects,
     * and only up to the number it expects — the drawer offers exactly that many slots,
     * so anything beyond it is a stale page or a hand-rolled request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $instance = $this->route('serviceordertaskinstance');
                $instance->loadMissing(['product.brand', 'product.productables.childProduct.brand', 'assets']);
                $slots = app(TaskInstanceSerialSlotService::class);

                $per_product = collect($this->input('assets'))
                    ->groupBy(fn (array $row) => (int) $row['product_id']);

                foreach ($per_product as $product_id => $rows) {
                    if (!$slots->expectsProduct($instance, (int) $product_id)) {
                        $validator->errors()->add(
                            'assets',
                            'Deze taak verwacht geen machines van dit product.'
                        );

                        return;
                    }

                    if ($rows->count() > $slots->remainingFor($instance, (int) $product_id)) {
                        $validator->errors()->add(
                            'assets',
                            'Er zijn meer serienummers ingevuld dan deze taak verwacht.'
                        );

                        return;
                    }
                }
            },
        ];
    }
}
