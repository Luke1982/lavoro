<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesEventFeedbackImages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $imageable_id
 * @property string $imageable_type
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageDestroyRequest extends FormRequest
{
    use AuthorizesEventFeedbackImages;

    public function authorize(): bool
    {
        if ($this->isEventFeedback()) {
            return $this->authorizeEventFeedback($this->route('image'));
        }

        return $this->user()->can('delete', $this->route('image'));
    }

    public function rules(): array
    {
        return [];
    }
}
