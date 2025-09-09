<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null $name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property bool|null $is_main
 * @method bool boolean(string $key, bool $default = false)
 * @method bool hasFile(string $key)
 * @method \Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]|null file(
 *     string $key = null,
 *     mixed $default = null
 * )
 */
class CompanyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for updating a company (partial allowed).
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
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
