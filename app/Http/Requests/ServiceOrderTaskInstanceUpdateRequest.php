<?php

namespace App\Http\Requests;

use App\Services\TaskInstanceSerialSlotService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderTaskInstanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return [
            'is_complete' => ['sometimes', 'boolean'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'user_role_ids' => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
        ];
    }

    /**
     * Registered machines point at the task that delivered them, so the product they were
     * registered under can no longer move and the quantity can no longer drop below what
     * is already standing at the customer — either would strand a real machine against a
     * task that no longer claims to have delivered it.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $instance = $this->route('serviceordertaskinstance');
                $instance->loadMissing(['product.productables.childProduct', 'assets']);

                if ($instance->assets->isEmpty()) {
                    return;
                }

                $product_changed = $this->has('product_id')
                    && (int) $this->input('product_id') !== (int) $instance->product_id;

                if ($product_changed) {
                    $validator->errors()->add(
                        'product_id',
                        'Deze taak heeft al serienummers geregistreerd; het product kan niet meer gewijzigd worden.'
                    );

                    return;
                }

                if (!$this->has('quantity')) {
                    return;
                }

                $candidate = clone $instance;
                $candidate->quantity = $this->input('quantity');
                $slots = app(TaskInstanceSerialSlotService::class);

                foreach ($slots->expectedCounts($candidate) as $expected) {
                    $registered = $instance->assets
                        ->where('product_id', $expected['product_id'])
                        ->count();

                    if ($expected['count'] < $registered) {
                        $validator->errors()->add(
                            'quantity',
                            'Er zijn al ' . $registered . ' serienummers geregistreerd voor '
                                . $expected['label'] . '; kies een hoger aantal.'
                        );

                        return;
                    }
                }
            },
        ];
    }
}
