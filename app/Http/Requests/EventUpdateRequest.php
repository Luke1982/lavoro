<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $user = $this->user();

        if (!$user || !$event) {
            return false;
        }
        if ($user->isAdmin() || $user->hasPermission('event.update_others')) {
            return true;
        }

        return $user->hasPermission('event.update') && $event->hasExecutingUser($user->id);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'event_type_id' => ['sometimes', 'exists:event_types,id'],
            'status' => ['sometimes', 'in:Gepland,Gaande,Afgerond,Geannuleerd'],
            'start' => ['sometimes', 'date_format:Y-m-d H:i'],
            'end' => ['sometimes', 'date_format:Y-m-d H:i', 'after_or_equal:start'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_preliminary' => ['sometimes', 'boolean'],
            'eventable_type' => ['sometimes', 'nullable', 'string', 'in:\\App\\Models\\ServiceOrder'],
            'eventable_id' => ['sometimes', 'nullable', 'exists:service_orders,id'],
            'create_service_order' => ['sometimes', 'nullable', 'boolean'],
            'executing_user_ids' => ['sometimes', 'array', 'min:1'],
            'executing_user_ids.*' => ['exists:users,id'],
            'executing_user_breaktimes' => ['sometimes', 'nullable', 'array'],
            'executing_user_breaktimes.*' => ['nullable', 'integer', 'min:0'],
            'executing_user_roles' => ['sometimes', 'nullable', 'array'],
            'executing_user_roles.*' => ['nullable', 'array'],
            'executing_user_roles.*.*' => ['integer', 'exists:user_roles,id'],
            'executing_user_diverging_times' => ['sometimes', 'nullable', 'array'],
            'executing_user_diverging_times.*.has_diverging_times' => ['nullable', 'boolean'],
            'executing_user_diverging_times.*.diverging_start' => ['nullable', 'date_format:H:i'],
            'executing_user_diverging_times.*.diverging_end' => ['nullable', 'date_format:H:i'],
            'breaktime' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'customer_id' => ['sometimes', 'required_if:create_service_order,true', 'nullable', 'exists:customers,id'],
        ];
    }
}
