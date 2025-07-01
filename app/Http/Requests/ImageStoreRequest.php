<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ImageStoreRequest
 *
 * @method \Illuminate\Routing\Route|null route(string $key = null)   Magic to grab route params
 * @method mixed                  input(string $key = null, mixed $default = null)  Magic to grab any input
 *
 * @property UploadedFile[]        $images          Array of uploaded image files
 * @property int                   $imageable_id    ID of the model to attach images to
 * @property string                $imageable_type  FQN of the model (e.g. App\Models\Post)
 * @property string[]|null         $titles          Optional titles, keyed by original filename
 */
class ImageStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'images.*'       => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'imageable_id'   => 'required|integer',
            'imageable_type' => 'required|string',
            'titles'         => 'array',
        ];
    }
}
