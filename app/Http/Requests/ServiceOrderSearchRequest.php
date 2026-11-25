<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderSearchRequest extends FormRequest
{
    /** The planner's picker, so the planner's rule: whoever may read appointments. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Event::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'include_id' => 'nullable|integer|exists:service_orders,id',
            'q' => 'nullable|string|max:100',
        ];
    }
}
