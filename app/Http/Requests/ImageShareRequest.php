<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sharing a photo with the work orders of its project, or with the customer of
 * its quote, or no longer. Where the photo is shared from is read from the
 * photo's own link, so nothing here names it.
 *
 * @property bool $shared
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share', $this->route('image'));
    }

    public function rules(): array
    {
        return [
            'shared' => 'required|boolean',
        ];
    }
}
