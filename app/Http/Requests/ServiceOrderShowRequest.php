<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderShowRequest extends FormRequest
{
    /** A werkbon that does not exist is left to the controller, which answers 404. */
    public function authorize(): bool
    {
        $service_order = ServiceOrder::find($this->route('serviceorder'));

        return $service_order === null || $this->user()->can('view', $service_order);
    }

    public function rules(): array
    {
        return [];
    }
}
