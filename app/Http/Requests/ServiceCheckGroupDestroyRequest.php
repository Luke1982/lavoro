<?php

namespace App\Http\Requests;

use App\Models\ServiceCheckGroup;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a keurpuntgroep asks for servicecheckgroup.delete.
 */
class ServiceCheckGroupDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', ServiceCheckGroup::class);
    }

    public function rules(): array
    {
        return [];
    }
}
