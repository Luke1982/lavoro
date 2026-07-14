<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User|null user(string $guard = null)
 */
class SerialScanRequest extends FormRequest
{
    /**
     * OCR reads a transient photo the user just took and stores nothing, so any
     * authenticated user may use it. The route already sits behind auth:sanctum.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:8192',
        ];
    }
}
