<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatuses;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    public function __construct(private ChangeProjectStatus $change_status) {}

    /**
     * A location and a contact person belong to the customer they were picked
     * from. Another customer takes them along, unless a new one comes with it.
     *
     * @param  array<string, mixed>  $data  the validated fields
     * @return string|null the warning for the person, if there is one
     */
    public function execute(Project $project, array $data): ?string
    {
        return DB::transaction(function () use ($project, $data) {
            $status = Arr::pull($data, 'status');

            if (array_key_exists('customer_id', $data) && (int) $data['customer_id'] !== (int) $project->customer_id) {
                $data += ['location_id' => null, 'contact_id' => null];
            }

            $project->update($data);

            return $status === null
                ? null
                : $this->change_status->execute($project, ProjectStatuses::from($status));
        });
    }
}
