<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.create'));
    }

    public function rules(): array
    {
        return [
            'service_order_id'      => ['required', 'integer', 'exists:service_orders,id'],
            'service_order_task_id' => ['nullable', 'integer', 'exists:service_order_tasks,id'],
            'product_id'            => ['nullable', 'integer', 'exists:products,id'],
            'quantity'              => ['nullable', 'integer', 'min:1', 'max:999'],
            'title'                 => ['nullable', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:500'],
            'is_complete'           => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (empty($this->service_order_task_id) && empty($this->description)) {
                $v->errors()->add('description', 'Vul een omschrijving in of kies een bestaande taak.');
            }
        });
    }
}
