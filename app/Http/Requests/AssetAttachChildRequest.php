<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssetAttachChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachChild', $this->route('asset'));
    }

    public function rules(): array
    {
        return [
            'child_asset_id' => ['required', 'integer', 'exists:assets,id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $parent = $this->route('asset');
                $child = Asset::find($this->child_asset_id);

                if (!$child) {
                    return;
                }

                if ($child->id === $parent->id) {
                    $validator->errors()->add('child_asset_id', 'Een machine kan niet onder zichzelf hangen.');

                    return;
                }

                if ($child->parent_asset_id !== null) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Deze machine hangt al onder een andere machine.'
                    );

                    return;
                }

                if ($child->customer_id !== $parent->resolvedCustomerId()) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Deze machine hoort niet bij dezelfde klant.'
                    );

                    return;
                }

                if ($this->wouldCycle($parent, $child)) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Deze machine hangt al boven de machine waaronder je hem wilt koppelen.'
                    );

                    return;
                }

                $parent_type_id = $parent->product?->productType?->id;
                $child_parent_type_id = $child->product?->productType?->parent_id;

                if ($parent_type_id === null || $child_parent_type_id !== $parent_type_id) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Het producttype van de te koppelen machine is niet compatibel met dit apparaat. '
                        . 'Alleen machines waarvan het producttype een subtype is van "'
                        . ($parent->product?->productType?->name ?? 'onbekend')
                        . '" kunnen worden gekoppeld.'
                    );
                }
            },
        ];
    }

    /**
     * Guards against hanging a machine under one of its own descendants, which would
     * cut the branch loose from any root and leave it owned by nobody.
     */
    private function wouldCycle(Asset $parent, Asset $child): bool
    {
        $node = $parent;

        while ($node->parent_asset_id !== null) {
            if ($node->parent_asset_id === $child->id) {
                return true;
            }

            $node = $node->parentAsset()->first();

            if (!$node) {
                return false;
            }
        }

        return false;
    }
}
