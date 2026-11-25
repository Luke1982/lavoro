<?php

namespace App\Http\Requests;

use App\Models\StandardAttachment;
use Illuminate\Foundation\Http\FormRequest;

class StandardAttachmentReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', StandardAttachment::class);
    }

    public function rules(): array
    {
        return [];
    }
}
