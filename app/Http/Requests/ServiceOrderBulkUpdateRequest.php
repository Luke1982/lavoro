<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('serviceorder.update');
    }

    public function rules(): array
    {
        return [
            'service_order_ids'   => ['required', 'array', 'min:1'],
            'service_order_ids.*' => ['integer', 'exists:service_orders,id'],
            'service_order_stage_id' => ['required', 'integer', 'exists:service_order_stages,id'],
        ];
    }
}
