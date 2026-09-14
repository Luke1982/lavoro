<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesEventFeedbackImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Renaming and annotating are separate permissions, so each part of the request
 * needs its own. An annotation save sends an empty title along, which renames nothing.
 *
 * @property UploadedFile|null $imageToUpdate
 * @property string|null $newTitle
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageUpdateRequest extends FormRequest
{
    use AuthorizesEventFeedbackImages;

    public function authorize(): bool
    {
        $image = $this->route('image');

        if ($this->isEventFeedback()) {
            return $this->authorizeEventFeedback($image);
        }

        if ($this->hasFile('imageToUpdate') && !$this->user()->can('edit', $image)) {
            return false;
        }

        return !$this->filled('newTitle') || $this->user()->can('update', $image);
    }

    public function rules(): array
    {
        return [
            'imageToUpdate' => 'nullable|required_without:newTitle|image|mimes:jpeg,png,jpg,gif|max:2048',
            'newTitle' => 'nullable|required_without:imageToUpdate|string|max:255',
        ];
    }
}
