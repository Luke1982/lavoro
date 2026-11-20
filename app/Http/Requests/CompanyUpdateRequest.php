<?php

namespace App\Http\Requests;

use App\Rules\Iban;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null $name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property string|null $coc_number
 * @property string|null $vat_number
 * @property string|null $iban
 * @property bool|null $is_main
 *
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
            'coc_number' => 'nullable|string|max:20',
            'vat_number' => 'nullable|string|max:20',
            'iban' => ['nullable', 'string', 'max:34', new Iban],
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'is_main' => 'sometimes|boolean',
            'logo' => 'nullable|image|max:2048',
            'logo_negative' => 'nullable|image|max:2048',
        ];
    }
}
