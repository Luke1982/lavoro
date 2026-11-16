<?php

namespace App\Http\Requests;

use App\Actions\Assets\AttachChildAssetAction;
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

                $child = Asset::find($this->child_asset_id);

                if (!$child) {
                    return;
                }

                $reason = app(AttachChildAssetAction::class)->refusal($this->route('asset'), $child);

                if ($reason !== null) {
                    $validator->errors()->add('child_asset_id', $reason);
                }
            },
        ];
    }
}
