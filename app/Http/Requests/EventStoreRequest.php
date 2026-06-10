<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
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
        $user = $this->user();

        return $user && ($user->isAdmin() || $user->hasPermission('event.create'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
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
            'location' => 'nullable|string|max:255',
            'is_preliminary' => 'nullable|boolean',
            'eventable_type' => 'nullable|string|in:\\App\\Models\\ServiceOrder',
            'eventable_id' => 'nullable|exists:service_orders,id',
            'create_service_order' => 'nullable|boolean',
            'customer_id' => 'required_if:create_service_order,true|nullable|exists:customers,id',
            'executing_user_ids' => 'required|array|min:1',
            'executing_user_ids.*' => 'exists:users,id',
            'executing_user_breaktimes' => 'nullable|array',
            'executing_user_breaktimes.*' => 'nullable|integer|min:0',
            'executing_user_roles' => 'nullable|array',
            'executing_user_roles.*' => 'nullable|array',
            'executing_user_roles.*.*' => 'integer|exists:user_roles,id',
            'breaktime' => 'nullable|integer|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('create_service_order') && ! $this->filled('eventable_id')) {
                $validator->errors()->add('eventable_id', 'Koppel een werkbon aan de afspraak of maak een nieuwe aan.');
            }
        });
    }
}
