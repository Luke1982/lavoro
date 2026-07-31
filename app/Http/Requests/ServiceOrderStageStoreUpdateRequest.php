<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksStageOrdering;
use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ServiceOrderStageStoreUpdateRequest extends FormRequest
{
    use ChecksStageOrdering;

    public function authorize(): bool
    {
        $user = Auth::user();
        $permission = $this->isMethod('post') ? 'serviceorderstage.create' : 'serviceorderstage.update';

        return $user && ($user->isAdmin() || $user->hasPermission($permission));
    }

    public function rules(): array
    {
        $stage = $this->route('serviceorderstage');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_planned_state' => [
                'sometimes',
                'boolean',
                function ($attribute, $value, $fail) use ($stage) {
                    if (!$value) {
                        return;
                    }
                    $effective = $this->has('is_planning_cancelled_state')
                        ? $this->boolean('is_planning_cancelled_state')
                        : ($stage?->is_planning_cancelled_state ?? false);
                    if ($effective) {
                        $fail('Een fase kan niet tegelijk de geplande en de geannuleerde fase zijn.');
                    }
                },
            ],
            'is_closed_state' => ['sometimes', 'boolean'],
            'is_invoiced_state' => ['sometimes', 'boolean'],
            'is_incomplete_state' => ['sometimes', 'boolean'],
            'is_plannable_state' => ['sometimes', 'boolean'],
            'is_planning_cancelled_state' => [
                'sometimes',
                'boolean',
                function ($attribute, $value, $fail) use ($stage) {
                    if (!$value) {
                        return;
                    }
                    $effective = $this->has('is_planned_state')
                        ? $this->boolean('is_planned_state')
                        : ($stage?->is_planned_state ?? false);
                    if ($effective) {
                        $fail('Een fase kan niet tegelijk de geplande en de geannuleerde fase zijn.');
                    }
                },
            ],
        ];
    }

    /**
     * Zowel het omzetten van een vlag als het verzetten van de volgorde kan de
     * gefactureerde fase voor de gesloten fase laten belanden, dus de toets kijkt
     * naar de uitkomst van deze wijziging in plaats van naar wat er binnenkwam.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /**
             * Alleen wie aan de vlaggen of de volgorde komt kan de regel breken. Een
             * naamswijziging blijft er dus buiten, ook als de fases al scheef stonden.
             */
            if (!$this->hasAny(['is_closed_state', 'is_invoiced_state', 'order'])) {
                return;
            }

            $stage = $this->route('serviceorderstage');
            $attribute = match (true) {
                $this->has('is_invoiced_state') => 'is_invoiced_state',
                $this->has('is_closed_state') => 'is_closed_state',
                default => 'order',
            };

            $this->failWhenInvoicedPrecedesClosed(
                $validator,
                $attribute,
                $this->stageOrderAfterChange('is_closed_state', $stage),
                $this->stageOrderAfterChange('is_invoiced_state', $stage)
            );
        });
    }

    /**
     * De volgorde van de fase die deze vlag na de wijziging draagt, of null wanneer
     * geen enkele fase hem dan nog draagt.
     */
    private function stageOrderAfterChange(string $flag, ?ServiceOrderStage $stage): ?int
    {
        $takes_flag = $this->has($flag) && $this->boolean($flag);
        $drops_flag = $this->has($flag) && !$this->boolean($flag);

        if ($takes_flag) {
            return $this->orderAfterChange($stage);
        }

        $holder = ServiceOrderStage::where($flag, true)
            ->when($stage && $drops_flag, fn ($q) => $q->whereKeyNot($stage->id))
            ->first();

        if (!$holder) {
            return null;
        }

        return $stage && $holder->id === $stage->id
            ? $this->orderAfterChange($stage)
            : (int) $holder->order;
    }

    private function orderAfterChange(?ServiceOrderStage $stage): int
    {
        if ($this->filled('order')) {
            return (int) $this->input('order');
        }

        return $stage ? (int) $stage->order : (int) (ServiceOrderStage::max('order') ?? 0) + 1;
    }
}
