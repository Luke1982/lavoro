<?php

namespace App\Http\Requests;

use App\Models\InternalAnnouncement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalAnnouncementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InternalAnnouncement::class);
    }

    /**
     * De einddatum is "tot en met", dus vandaag mag: een aankondiging die
     * alleen vandaag geldt is een geldige aankondiging. Gisteren niet — dan
     * verstuur je iets wat bij aankomst al verlopen is.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'is_for_everyone' => ['required', 'boolean'],
            'user_ids' => ['exclude_unless:is_for_everyone,false', 'required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'expires_on.after_or_equal' => 'De einddatum kan niet in het verleden liggen.',
            'user_ids.required' => 'Kies minstens één gebruiker, of stuur de aankondiging naar iedereen.',
            'user_ids.min' => 'Kies minstens één gebruiker, of stuur de aankondiging naar iedereen.',
        ];
    }
}
