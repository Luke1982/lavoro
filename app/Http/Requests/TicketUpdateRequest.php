<?php

namespace App\Http\Requests;

use App\Enums\TicketStatusses;
use App\Enums\TicketPriorities;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class TicketUpdateRequest
 *
 * This class handles the validation logic for updating a ticket.
 *
 * @property string|null $subject     The subject of the ticket.
 * @property string|null $description A detailed description of the ticket.
 * @property string|null $priority    The priority of the ticket.
 * @property string|null $status      The status of the ticket.
 * @property int|null    $asset_id    The ID of the associated asset.
 */
class TicketUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:' . implode(',', array_map(fn($prio) => $prio->value, TicketPriorities::cases())),
            'status' => 'nullable|in:' . implode(',', array_map(fn($status) => $status->value, TicketStatusses::cases())),
            'asset_id' => 'nullable|exists:assets,id',
        ];
    }

    public function messages()
    {
        return [
            'priority.in' => 'De opgegeven prioriteit is ongeldig.',
            'status.in' => 'De opgegeven status is ongeldig.',
            'asset_id.exists' => 'Het opgegeven asset bestaat niet.',
        ];
    }
}
