<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    /**
     * Accepting and asking for a change carry a name, a signature or a message,
     * so a plain status button does not set them. Declining may say why.
     */
    public function rules(): array
    {
        $allowed = array_map(fn (QuoteStatuses $status) => $status->value, $this->route('quote')->statusesByHand());
        $only_declining = 'prohibited_unless:status,' . QuoteStatuses::afgewezen->value;

        return [
            'status' => ['required', Rule::in($allowed)],
            'lost_reason_id' => ['nullable', 'integer', 'exists:quote_lost_reasons,id', $only_declining],
            'lost_reason_note' => ['nullable', 'string', 'max:2000', $only_declining],
        ];
    }
}
