<?php

namespace App\Http\Requests;

use App\Models\ServiceCheck;
use Illuminate\Foundation\Http\FormRequest;

class ServiceCheckValueDestroyRequest extends FormRequest
{
    /** The answers are part of a checkpoint's definition, so removing one is editing the checkpoint. */
    public function authorize(): bool
    {
        return $this->user()->can('update', ServiceCheck::class);
    }

    public function rules(): array
    {
        return [];
    }
}
