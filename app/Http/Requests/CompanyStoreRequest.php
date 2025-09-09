<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method bool boolean(string $key, bool $default = false)
 * @method bool hasFile(string $key)
 * @method \Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]|null file(
 *     string $key = null,
 *     mixed $default = null
 * )
 */
class CompanyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:32',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|size:2',
            'is_main' => 'sometimes|boolean',
            'logo' => 'nullable|image|max:2048',
        ];
    }
}
