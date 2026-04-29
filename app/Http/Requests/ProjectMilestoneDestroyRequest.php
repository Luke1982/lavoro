<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMilestoneDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('projectmilestone'));
    }

    public function rules(): array
    {
        return [];
    }
}
