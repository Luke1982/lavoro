<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class DocumentViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Document::class);
    }

    public function rules(): array
    {
        return [];
    }
}
