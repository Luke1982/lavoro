<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesEventFeedbackImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Renaming and annotating are separate permissions, so each part of the request
 * needs its own. An annotation save sends an empty title along, which renames nothing.
 *
 * An annotation save sends the drawing along as `annotations`, so it can be opened
 * again on the untouched photo; a file without it replaces the photo outright.
 *
 * @property UploadedFile|null $imageToUpdate
 * @property string|null $newTitle
 * @property array|null $annotations
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

    /**
     * The drawing arrives as JSON inside a multipart upload. Only JSON that decodes is
     * unpacked: anything else stays a string and fails the array rule, instead of
     * passing for "no drawing", which would replace the photo and drop its original.
     */
    protected function prepareForValidation(): void
    {
        $annotations = $this->input('annotations');

        if (!is_string($annotations)) {
            return;
        }

        $decoded = json_decode($annotations, true);

        if (is_array($decoded)) {
            $this->merge(['annotations' => $decoded]);
        }
    }

    /**
     * The points of a stroke are bounded but not checked one by one: a few freehand
     * strokes hold thousands of numbers, and that costs seconds per save.
     */
    public function rules(): array
    {
        return [
            'imageToUpdate' => 'nullable|required_without:newTitle|image|mimes:jpeg,png,jpg,gif|max:2048',
            'newTitle' => 'nullable|required_without:imageToUpdate|string|max:255',
            'annotations' => 'nullable|array:version,width,height,crop,shapes',
            'annotations.version' => 'required_with:annotations|integer',
            'annotations.width' => 'required_with:annotations|integer|min:1',
            'annotations.height' => 'required_with:annotations|integer|min:1',
            'annotations.crop' => 'nullable|array:x,y,width,height',
            'annotations.crop.x' => 'required_with:annotations.crop|numeric|min:0',
            'annotations.crop.y' => 'required_with:annotations.crop|numeric|min:0',
            'annotations.crop.width' => 'required_with:annotations.crop|numeric|gt:0',
            'annotations.crop.height' => 'required_with:annotations.crop|numeric|gt:0',
            'annotations.shapes' => 'present_with:annotations|array|max:500',
            'annotations.shapes.*' => 'array:id,type,colour,size,points,text,x,y',
            'annotations.shapes.*.id' => 'required|string|max:40',
            'annotations.shapes.*.type' => 'required|in:draw,arrow,text',
            'annotations.shapes.*.colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'annotations.shapes.*.size' => 'required|numeric|gt:0',
            'annotations.shapes.*.points' => 'required_unless:annotations.shapes.*.type,text|array|max:20000',
            'annotations.shapes.*.text' => 'required_if:annotations.shapes.*.type,text|string|max:120',
            'annotations.shapes.*.x' => 'required_if:annotations.shapes.*.type,text|numeric',
            'annotations.shapes.*.y' => 'required_if:annotations.shapes.*.type,text|numeric',
        ];
    }
}
