<?php

namespace App\Http\Requests;

use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Read-only lookahead for the transfer modal: what would move, which locations need a
 * mapping, and which contracts lose the machine. Authorised against whichever record the
 * customer is being changed on, so the preview can never reveal more than the edit itself.
 */
class AssetTransferPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject !== null && $this->user()->can('update', $subject);
    }

    public function rules(): array
    {
        return [
            'context' => ['required', 'in:contract,serviceorder,asset'],
            'id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ];
    }

    public function subject(): ?Model
    {
        return match ($this->input('context')) {
            'contract' => MaintenanceContract::find($this->input('id')),
            'serviceorder' => ServiceOrder::find($this->input('id')),
            'asset' => Asset::find($this->input('id')),
            default => null,
        };
    }
}
