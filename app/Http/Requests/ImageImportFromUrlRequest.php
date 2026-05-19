<?php

namespace App\Http\Requests;

use App\Models\Image;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $url
 * @property int    $imageable_id
 * @property string $imageable_type
 * @property string $name
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageImportFromUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Image::class);
    }

    public function rules(): array
    {
        return [
            'url'            => ['required', 'string', 'max:2097152', function ($attribute, $value, $fail) {
                if (!str_starts_with($value, 'data:image/') && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $fail('Geef een geldige URL of base64 data-URI op.');
                }
            }],
            'imageable_id'   => 'required|integer',
            'imageable_type' => 'required|string',
            'name'           => 'nullable|string|max:255',
        ];
    }
}
