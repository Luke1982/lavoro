<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesEventFeedbackImages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The drawing on a photo is only read to draw on further, so it takes what
 * annotating takes.
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageAnnotationsReadRequest extends FormRequest
{
    use AuthorizesEventFeedbackImages;

    public function authorize(): bool
    {
        $image = $this->route('image');

        if ($this->isEventFeedback()) {
            return $this->authorizeEventFeedback($image);
        }

        return $this->user()->can('edit', $image);
    }

    public function rules(): array
    {
        return [];
    }
}
