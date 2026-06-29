<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service_order = $this->route('serviceorder');

        return $this->user()->can('delete', $service_order);
    }

    public function rules(): array
    {
        return [];
    }
}
