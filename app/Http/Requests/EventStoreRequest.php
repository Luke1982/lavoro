<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class EventStoreRequest
 *
 * @property string|null $name
 * @property string|null $description
 * @property int $event_type_id
 * @property string $status
 * @property string $start
 * @property string $end
 * @property string|null $eventable_type
 * @property int|null $eventable_id
 */
class EventStoreRequest extends FormRequest
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
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'event_type_id' => 'required|exists:event_types,id',
            'status' => 'required|in:Gepland,Gaande,Afgerond,Geannuleerd',
            'start' => 'required|date_format:Y-m-d H:i',
            'end' => 'required|date_format:Y-m-d H:i|after_or_equal:start',
            'eventable_type' => 'nullable|string|in:\\App\\Models\\ServiceOrder',
            'eventable_id' => 'nullable|exists:service_orders,id',
        ];
    }
}
