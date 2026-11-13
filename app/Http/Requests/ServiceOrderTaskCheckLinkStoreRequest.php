<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskCheckLink;
use App\Models\ServiceOrderTaskChecklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A check dropped onto a checklist or a task. The client names the kind of
 * target; which model that is, is decided here.
 */
class ServiceOrderTaskCheckLinkStoreRequest extends FormRequest
{
    public const TARGETS = [
        'task' => ServiceOrderTask::class,
        'checklist' => ServiceOrderTaskChecklist::class,
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [
            'check_id' => ['required', 'integer', 'exists:service_order_task_checks,id'],
            'target' => ['required', 'string', Rule::in(array_keys(self::TARGETS))],
            'target_id' => ['required', 'integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $class = $this->targetClass();

                if (!$class::whereKey($this->integer('target_id'))->exists()) {
                    $validator->errors()->add('target_id', 'Dit onderdeel bestaat niet meer.');

                    return;
                }

                $taken = ServiceOrderTaskCheckLink::where('service_order_task_check_id', $this->integer('check_id'))
                    ->where('checkable_type', $class)
                    ->where('checkable_id', $this->integer('target_id'))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('check_id', 'Deze check zit hier al in.');
                }
            },
        ];
    }

    public function targetClass(): string
    {
        return self::TARGETS[$this->input('target')];
    }
}
