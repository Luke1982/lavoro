<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklistLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A checklist dropped onto a task. */
class ServiceOrderTaskChecklistLinkStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [
            'checklist_id' => ['required', 'integer', 'exists:service_order_task_checklists,id'],
            'task_id' => ['required', 'integer', 'exists:service_order_tasks,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $taken = ServiceOrderTaskChecklistLink::where('service_order_task_checklist_id', $this->integer('checklist_id'))
                    ->where('checklistable_type', ServiceOrderTask::class)
                    ->where('checklistable_id', $this->integer('task_id'))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('checklist_id', 'Deze checklist zit al in deze taak.');
                }
            },
        ];
    }
}
