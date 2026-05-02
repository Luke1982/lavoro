<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class AssetRelationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('assetrelation.create');
    }

    public function rules(): array
    {
        return [
            'parent_asset_id'     => ['required', 'integer', 'exists:assets,id'],
            'child_asset_id'      => ['required', 'integer', 'exists:assets,id', 'different:parent_asset_id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) return;

                $parent = Asset::with('product.productType')->find($this->parent_asset_id);
                $child  = Asset::with('product.productType')->find($this->child_asset_id);

                $parentTypeId      = $parent?->product?->productType?->id;
                $childParentTypeId = $child?->product?->productType?->parent_id;

                if ($parentTypeId === null || $childParentTypeId !== $parentTypeId) {
                    $validator->errors()->add(
                        'child_asset_id',
                        'Het producttype van de te koppelen machine is niet compatibel met dit apparaat. '
                        . 'Alleen machines waarvan het producttype een subtype is van "'
                        . ($parent?->product?->productType?->name ?? 'onbekend')
                        . '" kunnen worden gekoppeld.'
                    );
                }
            },
        ];
    }
}
