<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesEventFeedbackImages;
use App\Models\Image;
use App\Rules\AttachableType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $imageable_id
 * @property string $imageable_type
 *
 * @method \App\Models\Image|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageSetMainRequest extends FormRequest
{
    use AuthorizesEventFeedbackImages;

    /**
     * Whoever may edit the photo may make it the main photo of the record it hangs
     * off, and of no other: the record named here is the one whose main photo is
     * cleared. A type that is not on the list is let through to rules(), which
     * refuses it with a message.
     */
    public function authorize(): bool
    {
        if ($this->isEventFeedback()) {
            return $this->authorizeEventFeedback($this->route('image'));
        }

        $image = $this->route('image');
        $record_class = Image::attachableType($this->input('imageable_type'));

        if ($record_class !== null && !$image->isAttachedTo($record_class, $this->integer('imageable_id'))) {
            return false;
        }

        return $this->user()->can('update', $image);
    }

    public function rules(): array
    {
        return [
            'imageable_type' => ['required', 'string', new AttachableType(Image::ATTACHABLE_TO, 'afbeelding')],
            'imageable_id' => 'required|integer',
            'currently_main' => 'sometimes|boolean',
        ];
    }
}
