<?php

namespace App\Http\Requests;

use App\Models\StandardAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardAttachmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardAttachment::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}
