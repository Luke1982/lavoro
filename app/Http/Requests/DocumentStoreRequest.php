<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * @property int $documentable_id
 * @property string $documentable_type
 * @property int|null $document_category_id
 * @property UploadedFile[] $documents
 *
 * @method \Illuminate\Http\UploadedFile[] file(string $key, mixed $default = null)
 * @method \App\Models\User user()
 */
class DocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Document::class);
    }

    /**
     * csv rides alongside txt on purpose: a .csv is routinely sniffed as
     * text/plain, so the txt entry is what lets those through.
     */
    private const ALLOWED_MIMES = 'pdf,odt,odf,ods,doc,docx,xls,xlsx,csv,ppt,pptx,txt,jpg,jpeg,png';

    public function rules(): array
    {
        return [
            'documents.*' => 'required|file|mimes:' . self::ALLOWED_MIMES . '|max:20480',
            'documentable_id' => 'required|integer',
            'documentable_type' => 'required|string',
            'document_category_id' => 'nullable|integer|exists:document_categories,id',
            'internal' => 'nullable|boolean',
        ];
    }
}
