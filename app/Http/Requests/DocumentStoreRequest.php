<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $documentable_id
 * @property string $documentable_type
 * @property \Illuminate\Http\UploadedFile[] $documents
 *
 * @method \Illuminate\Http\UploadedFile[] file(string $key, mixed $default = null)
 */
class DocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'documents.*' => 'required|file|mimes:pdf,odt,odf,doc,docx,xls,xlsx,ppt,pptx,txt|max:10240',
            'documentable_id' => 'required|integer',
            'documentable_type' => 'required|string',
        ];
    }
}
