<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalAnnouncementAcknowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('acknowledge', $this->route('internalannouncement'));
    }

    public function rules(): array
    {
        return [];
    }
}
