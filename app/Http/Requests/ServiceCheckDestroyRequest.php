<?php

namespace App\Http\Requests;

use App\Models\ServiceCheck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a keurpunt asks for servicecheck.delete.
 */
class ServiceCheckDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', ServiceCheck::class);
    }

    public function rules(): array
    {
        return [];
    }
}
