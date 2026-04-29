<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ProjectReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        if ($project) {
            return $this->user()->can('view', $project);
        }
        return $this->user()->can('list', Project::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
