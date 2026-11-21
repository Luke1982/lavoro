<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceCheckValueDestroyRequest extends FormRequest
{
    /** The answers are part of a checkpoint's definition, so removing one is editing the checkpoint. */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('servicecheck.update');
    }

    public function rules(): array
    {
        return [];
    }
}
