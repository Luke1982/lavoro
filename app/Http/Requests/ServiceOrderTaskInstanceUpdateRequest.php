<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFlexQuantities;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceBundleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderTaskInstanceUpdateRequest extends FormRequest
{
    use ValidatesFlexQuantities;

    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return array_merge([
            'is_complete' => ['sometimes', 'boolean'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'user_role_ids' => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
        ], $this->flexQuantityRules());
    }

    /**
     * Registered machines point at the task that delivered them, so the product they were
     * registered under can no longer move, the aantal can no longer drop below the bundles
     * already standing at the customer, and a flex aantal can no longer drop below what is
     * in a bundle — each would strand a real machine against a task that no longer claims
     * to have delivered it.
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

                $product_changed = $this->has('product_id')
                    && (int) $this->input('product_id') !== (int) $instance->product_id;

                $product = $product_changed
                    ? Product::with('productables.childProduct')->find($this->input('product_id'))
                    : $instance->product;

                if ($this->has('flex_parts') || $product_changed) {
                    $this->validateFlexQuantities($validator, $product);

                    if ($validator->errors()->isNotEmpty()) {
                        return;
                    }
                }

                if ($instance->assets->isEmpty()) {
                    return;
                }

                if ($product_changed) {
                    $validator->errors()->add(
                        'product_id',
                        'Deze taak heeft al machines geregistreerd; het product kan niet meer gewijzigd worden.'
                    );

                    return;
                }

                $this->checkQuantity($validator, $instance, $product);
                $this->checkFlexQuantities($validator, $instance, $product);
            },
        ];
    }

    private function checkQuantity(Validator $validator, ServiceOrderTaskInstance $instance, ?Product $product): void
    {
        if (!$this->has('quantity')) {
            return;
        }

        $wanted = max(1, (int) $this->input('quantity'));

        if ($product?->bundle) {
            $used = app(TaskInstanceBundleService::class)->usedContainerCount($instance);

            if ($wanted < $used) {
                $validator->errors()->add(
                    'quantity',
                    'Er zijn al onderdelen geregistreerd voor ' . $used . ' bundels; kies een hoger aantal.'
                );
            }

            return;
        }

        $registered = $instance->assets->count();

        if ($wanted < $registered) {
            $validator->errors()->add(
                'quantity',
                'Er zijn al ' . $registered . ' machines geregistreerd voor deze taak; kies een hoger aantal.'
            );
        }
    }

    private function checkFlexQuantities(
        Validator $validator,
        ServiceOrderTaskInstance $instance,
        ?Product $product,
    ): void {
        if (!$this->has('flex_parts') || $validator->errors()->isNotEmpty()) {
            return;
        }

        $flex_parts = $this->flexPartsOf($product);

        if ($flex_parts->isEmpty()) {
            return;
        }

        $chosen = collect($this->input('flex_parts', []))
            ->mapWithKeys(fn (array $row) => [(int) $row['product_id'] => (int) $row['quantity']]);

        $registered = $instance->assets->whereNotNull('parent_asset_id');

        foreach ($registered->groupBy('product_id') as $product_id => $parts) {
            $product_id = (int) $product_id;

            if (!$flex_parts->has($product_id)) {
                continue;
            }

            $per_bundle = $parts->groupBy('parent_asset_id')->map->count()->max();

            if (($chosen[$product_id] ?? 0) >= $per_bundle) {
                continue;
            }

            $label = $flex_parts->get($product_id)->childProduct?->display_name ?? 'dit onderdeel';

            $validator->errors()->add(
                'flex_parts',
                'Er zijn al ' . $per_bundle . ' machines van ' . $label
                    . ' in één bundel geregistreerd; kies geen lager aantal.'
            );

            return;
        }
    }
}
