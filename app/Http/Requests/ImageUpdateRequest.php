<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Image;
use Illuminate\Http\UploadedFile;

/**
 * @property UploadedFile|null $imageToUpdate
 * @property string|null $newTitle
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 * @method bool has(string $key)
 * @method bool hasFile(string $key)
 */
class ImageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $image = $this->route('image');
        if ($this->has('newTitle')) {
            return $this->user()->can('update', $image);
        }
        if ($this->hasFile('imageToUpdate')) {
            return $this->user()->can('edit', $image);
        }
        return false;
    }

    public function rules(): array
    {
        return [
            'imageToUpdate' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'newTitle' => 'nullable|string|max:255',
        ];
    }
}
