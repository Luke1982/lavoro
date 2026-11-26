<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The file of a photo, drawn or as taken: for whoever may open the record it
 * hangs off, or one it is shared to (ImagePolicy::view).
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageFileReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('image'));
    }

    public function rules(): array
    {
        return [];
    }
}
