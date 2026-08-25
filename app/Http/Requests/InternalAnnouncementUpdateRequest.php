<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalAnnouncementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('internalannouncement'));
    }

    /**
     * Elk veld is 'sometimes': de detailpagina slaat per veld op, dus een PATCH
     * draagt zelden meer dan één sleutel.
     *
     * Op user_ids staat bewust geen 'sometimes' maar exclude_unless. Dat dekt
     * alle drie de gevallen in één regel: komt de schakelaar niet mee, dan gaat
     * deze PATCH niet over de doelgroep; staat hij op iedereen, dan is de lijst
     * betekenisloos en verdwijnt hij; staat hij uit, dan moet er iemand in staan.
     * Met 'sometimes' zou die laatste eis juist overgeslagen worden in het enige
     * geval waarin hij iets betekent: een lege lijst.
     *
     * De einddatum mag hier wél in het verleden liggen, anders dan bij het
     * aanmaken: dat is hoe je een aankondiging vroegtijdig sluit zonder de
     * bevestigingen weg te gooien die verwijderen wel kost.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:5000'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'is_for_everyone' => ['sometimes', 'boolean'],
            'user_ids' => ['exclude_unless:is_for_everyone,false', 'required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'user_ids.required' => 'Kies minstens één gebruiker, of stuur de aankondiging naar iedereen.',
            'user_ids.min' => 'Kies minstens één gebruiker, of stuur de aankondiging naar iedereen.',
        ];
    }
}
