<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ProjectReadRequest extends FormRequest
{
    /** The list opens on the projects still running; 'all' is said out loud to see every one. */
    public const RUNNING = 'running';

    public const EVERY_STATUS = 'all';

    public const SORTABLE = ['number', 'title', 'customer', 'end_date', 'status'];

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
            'status' => ['sometimes', 'nullable', 'in:' . implode(',', [
                self::RUNNING,
                self::EVERY_STATUS,
                ...array_column(ProjectStatuses::cases(), 'value'),
            ])],
            'manager' => ['sometimes', 'nullable', 'integer'],
            'customer' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', 'in:' . implode(',', self::SORTABLE)],
            'direction' => ['sometimes', 'nullable', 'in:asc,desc'],
        ];
    }

    /** @return array{search: string, status: string, manager: ?int, customer: ?int, sort: ?string, direction: string} */
    public function listQuery(): array
    {
        return [
            'search' => trim((string) $this->validated('search', '')),
            'status' => $this->validated('status') ?? self::RUNNING,
            'manager' => $this->validated('manager') ? (int) $this->validated('manager') : null,
            'customer' => $this->validated('customer') ? (int) $this->validated('customer') : null,
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction') ?? 'asc',
        ];
    }
}
