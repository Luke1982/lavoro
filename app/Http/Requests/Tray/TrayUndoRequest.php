<?php

namespace App\Http\Requests\Tray;

use App\Domain\Tray\Tray;
use App\Domain\Tray\TrayKinds;
use App\Models\TrayItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Takes a paste back. Everything in it came from the server, but it travels
 * through the browser, so the paste checks every right again on the way back.
 */
class TrayUndoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('paste', TrayItem::class);
    }

    public function rules(): array
    {
        return [
            'undo' => ['required', 'array'],
            'undo.handler' => ['required', 'string', Rule::in(Tray::undoable())],
            'undo.kind' => ['required', 'string', Rule::in(TrayKinds::names())],
            'undo.record_ids' => ['required', 'array', 'min:1', 'max:100'],
            'undo.record_ids.*' => ['integer'],
            'undo.service_order_id' => [
                Rule::requiredIf($this->undoes('ticket:serviceorder') || $this->undoes('asset:serviceorder')),
                'integer',
            ],
            'undo.ticket_ids' => [Rule::requiredIf($this->undoes('ticket:serviceorder')), 'array', 'max:100'],
            'undo.ticket_ids.*' => ['integer'],
            'undo.service_job_ids' => [Rule::requiredIf($this->undoes('asset:serviceorder')), 'array', 'max:500'],
            'undo.service_job_ids.*' => ['integer'],
            'undo.parent_asset_id' => [Rule::requiredIf($this->undoes('asset:asset')), 'integer'],
            'undo.locations' => [Rule::requiredIf($this->undoes('asset:asset')), 'array', 'max:100'],
            'undo.locations.*' => ['nullable', 'integer'],
            'undo.maintenancecontract_id' => [Rule::requiredIf($this->undoes('asset:maintenancecontract')), 'integer'],
            'undo.assetable_ids' => [Rule::requiredIf($this->undoes('asset:maintenancecontract')), 'array', 'max:100'],
            'undo.assetable_ids.*' => ['integer'],
        ];
    }

    private function undoes(string $handler): bool
    {
        return $this->input('undo.handler') === $handler;
    }
}
