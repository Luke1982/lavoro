<?php

namespace App\Http\Requests;

use App\Models\EventType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing an afspraaktype asks for eventtype.delete.
 */
class EventTypeDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', EventType::class);
    }

    public function rules(): array
    {
        return [];
    }
}
