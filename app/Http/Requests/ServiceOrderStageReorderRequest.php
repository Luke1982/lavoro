<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksStageOrdering;
use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderStageReorderRequest extends FormRequest
{
    use ChecksStageOrdering;

    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('serviceorderstage.update'));
    }

    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'payload.*.id' => ['required', 'integer', 'exists:service_order_stages,id'],
            'payload.*.order' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * De lijst gaat per pagina mee, dus een fase die er niet in staat houdt de
     * volgorde die ze al had.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $closed = ServiceOrderStage::where('is_closed_state', true)->first();
            $invoiced = ServiceOrderStage::where('is_invoiced_state', true)->first();

            $this->failWhenInvoicedPrecedesClosed(
                $validator,
                'payload',
                $closed ? $this->orderAfterMove($closed) : null,
                $invoiced ? $this->orderAfterMove($invoiced) : null
            );
        });
    }

    private function orderAfterMove(ServiceOrderStage $stage): int
    {
        foreach ($this->input('payload', []) as $row) {
            if (isset($row['id'], $row['order']) && (int) $row['id'] === $stage->id) {
                return (int) $row['order'];
            }
        }

        return (int) $stage->order;
    }
}
