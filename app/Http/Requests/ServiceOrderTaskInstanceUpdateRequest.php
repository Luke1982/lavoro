<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsTaskInstanceForm;
use App\Http\Requests\Concerns\ValidatesFlexQuantities;
use App\Models\Product;
use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskChecks\TaskCheckExtras;
use App\Services\TaskChecks\TaskCheckUnits;
use App\Services\TaskChecks\TaskInstanceChecks;
use App\Services\TaskInstanceBundleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderTaskInstanceUpdateRequest extends FormRequest
{
    use ReadsTaskInstanceForm;
    use ValidatesFlexQuantities;

    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return array_merge([
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'user_role_ids' => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
            'checks_per_unit' => ['sometimes', 'boolean'],
        ], $this->extraCheckRules(), $this->flexQuantityRules());
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

                $this->checkChecks($validator, $instance, $product);

                if ($validator->errors()->isNotEmpty() || $instance->assets->isEmpty()) {
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

    /**
     * The checks on the task hold an edit back where it would undo ticked work: the
     * per-machine switch once anything is ticked, an aantal that drops a machine with
     * ticks or a registered machine, and any change of machines or extras on a
     * completed task.
     */
    private function checkChecks(Validator $validator, ServiceOrderTaskInstance $instance, ?Product $product): void
    {
        $switching = $this->has('checks_per_unit')
            && $this->boolean('checks_per_unit') !== (bool) $instance->checks_per_unit;

        if ($switching && app(TaskInstanceChecks::class)->hasTicks($instance)) {
            $validator->errors()->add('checks_per_unit', 'Er zijn al checks afgevinkt; dit kan niet meer wijzigen.');
        }

        $unit_count = TaskCheckUnits::count(
            $product?->id,
            $this->has('quantity') ? $this->integer('quantity') : $instance->quantity,
        );
        $unit_count_changes = $unit_count !== TaskCheckUnits::count($instance->product_id, $instance->quantity);
        $blocking = $switching ? null : app(TaskCheckUnits::class)->blocking($instance, $unit_count, (bool) $product?->bundle);

        if ($instance->is_complete && $instance->checks_per_unit && $unit_count_changes) {
            $validator->errors()->add('quantity', 'Deze taak is al voltooid; heropen hem om het aantal te wijzigen.');
        } elseif ($blocking !== null) {
            $validator->errors()->add('quantity', $blocking);
        }

        $extras = $this->extraChecks();
        $extra_checks = app(TaskCheckExtras::class);

        if ($extras === null) {
            return;
        }

        if ($instance->is_complete && $extra_checks->wouldChange($instance, $extras)) {
            $validator->errors()->add('extra_check_ids', 'Deze taak is al voltooid.');
        } elseif ($extra_checks->dropsTicked($instance, $extras)) {
            $validator->errors()->add('extra_check_ids', 'Een extra check die al is afgevinkt kan niet weg.');
        }
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
