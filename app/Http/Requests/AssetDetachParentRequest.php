<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetDetachParentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('detachParent', $this->route('asset'));
    }

    public function rules(): array
    {
        return [];
    }
}
