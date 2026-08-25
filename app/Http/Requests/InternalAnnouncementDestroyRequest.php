<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternalAnnouncementDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('internalannouncement'));
    }

    public function rules(): array
    {
        return [];
    }
}
