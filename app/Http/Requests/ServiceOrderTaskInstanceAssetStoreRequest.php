<?php

namespace App\Http\Requests;

use App\Models\Product;
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

    /**
     * A machine only needs a serienummer when its product carries one; a part that is not
     * registable is still registered, it just has nothing to type. Spelled out per row
     * rather than through a wildcard, because an empty field reaches here as null and a
     * `nullable` wildcard would wave it through for every product alike.
     */
    public function rules(): array
    {
        $rows = $this->input('assets');
        $rows = is_array($rows) ? $rows : [];

        $products = Product::whereIn('id', collect($rows)->pluck('product_id')->filter())
            ->get()
            ->keyBy('id');

        $rules = [
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'assets.*.container_index' => ['present', 'nullable', 'integer', 'min:0'],
        ];

        foreach ($rows as $index => $row) {
            $requires_serial = $products->get($row['product_id'] ?? null)?->requiresSerial() ?? true;

            $rules["assets.{$index}.serial_number"] = [
                $requires_serial ? 'required' : 'nullable',
                'string',
                'max:255',
                UniqueSerialForProduct::fromRow(),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'assets.*.serial_number.required' => 'Serienummer is verplicht.',
        ];
    }

    /**
     * Machines may only be registered against the slots the taak actually offers, and only
     * up to the number each slot expects — the drawer offers exactly that many, so anything
     * beyond it is a stale page or a hand-rolled request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $instance = $this->route('serviceordertaskinstance');
                $instance->loadMissing([
                    'product.brand',
                    'product.productables.childProduct.brand',
                    'productables',
                    'assets',
                ]);

                $slots = collect(app(TaskInstanceSerialSlotService::class)->groups($instance))
                    ->keyBy('key');

                $posted = collect($this->input('assets'))->countBy(fn (array $row) => TaskInstanceSerialSlotService::slotKey(
                    $row['container_index'] === null ? null : (int) $row['container_index'],
                    (int) $row['product_id'],
                ));

                foreach ($posted as $key => $count) {
                    $slot = $slots->get($key);

                    if (!$slot) {
                        $validator->errors()->add(
                            'assets',
                            'Deze taak verwacht hier geen machines van dit product.'
                        );

                        return;
                    }

                    if ($count > $slot['expected'] - count($slot['assets'])) {
                        $validator->errors()->add(
                            'assets',
                            'Er zijn meer machines ingevuld dan deze taak verwacht.'
                        );

                        return;
                    }
                }
            },
        ];
    }
}
