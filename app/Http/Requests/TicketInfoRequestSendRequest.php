<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Services\TicketInfoRequestRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method \App\Models\User|null user(string $guard = null)
 */
class TicketInfoRequestSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestCustomerInfo', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],

            /**
             * Zonder gevraagde soorten is het een mail die om niets vraagt, met een
             * link naar een pagina die niet weet wat hij moet tonen.
             */
            'requested' => ['required', 'array', 'min:1'],
            'requested.*' => ['string', Rule::in(array_keys(TicketInfoRequestRenderer::REQUESTABLE))],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Er is geen ontvanger ingevuld.',
            'to.email' => 'Dit is geen geldig e-mailadres.',
            'subject.required' => 'Het onderwerp is verplicht.',
            'body.required' => 'Het bericht is leeg.',
            'requested.required' => 'Geef aan welke informatie u van de klant wilt ontvangen.',
            'requested.min' => 'Geef aan welke informatie u van de klant wilt ontvangen.',
        ];
    }

    public function ticket(): Ticket
    {
        return $this->route('ticket');
    }

    /** @return array<int, string> */
    public function requested(): array
    {
        return array_values(array_unique($this->validated('requested')));
    }
}
