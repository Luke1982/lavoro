<?php

namespace App\Http\Requests;

use App\Domain\Planning\Clock;
use App\Models\DayRoute;
use App\Models\User;
use App\Services\RoutePlanner;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DayRouteStoreRequest extends FormRequest
{
    private const NO_MECHANIC = 'Kies een monteur die in de planner staat.';

    private ?User $mechanic = null;

    /** Without a valid person yet, the rules say so; who may plan them is asked once there is one. */
    public function authorize(): bool
    {
        $mechanic = $this->mechanic();

        return $mechanic === null
            ? (bool) $this->user()?->can('routeplanner.use')
            : (bool) $this->user()?->can('assign', [DayRoute::class, $mechanic]);
    }

    /** Only someone who appears in the planner has a day to put a route in. */
    public function mechanic(): ?User
    {
        return $this->mechanic ??= User::query()->where('plannable', true)->find($this->integer('user_id'));
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->mechanic() === null) {
                        $fail(self::NO_MECHANIC);
                    }
                },
            ],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . Clock::today()],
            'stops' => ['required', 'array', 'min:1', 'max:' . RoutePlanner::MAX_STOPS],
            'stops.*.name' => ['required', 'string', 'max:255'],
            'stops.*.arrival' => ['required', 'date_format:H:i'],
            'finish' => ['required', 'date_format:H:i'],
            'links' => ['required', 'array', 'min:1', 'max:' . DayRoute::MAX_LINKS],
            'links.*' => ['required', 'string', 'max:2048', 'starts_with:https://www.google.com/maps/dir/?'],
        ];
    }

    public function messages(): array
    {
        $recalculate = 'Bereken de route opnieuw en zet hem dan in de planning.';

        return [
            'user_id.*' => self::NO_MECHANIC,
            'date.after_or_equal' => 'Een route kan niet meer in een dag die al voorbij is.',
            'date.*' => 'Kies de dag waarop de route gereden wordt.',
            'stops.*' => $recalculate,
            'finish.*' => $recalculate,
            'links.*' => $recalculate,
        ];
    }
}
