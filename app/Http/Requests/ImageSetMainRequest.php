<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int    $imageable_id
 * @property string $imageable_type
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null  user(string $guard = null)
 */
class ImageSetMainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('image'));
    }

    public function rules(): array
    {
        return [
            'imageable_id'   => 'required|integer',
            'imageable_type' => 'required|string',
            'currently_main' => 'sometimes|boolean',
        ];
    }
}
