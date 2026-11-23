<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PlacesOnARecord;
use App\Models\Image;
use App\Rules\AttachableType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $url
 * @property int $imageable_id
 * @property string $imageable_type
 * @property string $name
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class ImageImportFromUrlRequest extends FormRequest
{
    use PlacesOnARecord;

    /**
     * The imported photo becomes the record's main photo, so it takes the right
     * to choose one as well as the right to upload, on a record this person may see.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Image::class)
            && $this->user()->can('update', Image::class)
            && $this->maySeeTheRecord(Image::class);
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2097152', function ($attribute, $value, $fail) {
                if (!str_starts_with($value, 'data:image/') && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $fail('Geef een geldige URL of base64 data-URI op.');
                }
            }],
            'imageable_id' => 'required|integer',
            'imageable_type' => ['required', 'string', new AttachableType(Image::ATTACHABLE_TO, 'afbeelding')],
            'name' => 'nullable|string|max:255',
        ];
    }
}
