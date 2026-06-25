<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTask;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('post')) {
            return $this->user()->can('create', ServiceOrderTask::class);
        }

        return $this->user()->can('update', $this->route('serviceordertask'));
    }

    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
