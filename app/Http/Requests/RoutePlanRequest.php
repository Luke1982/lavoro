<?php

namespace App\Http\Requests;

use App\Domain\Planning\TechnicianAvailability;
use App\Services\RoutePlanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoutePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('routeplanner.use');
    }

    public function rules(): array
    {
        return [
            'stops' => ['required', 'array', 'min:1', 'max:' . RoutePlanner::MAX_STOPS],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.name' => ['nullable', 'string', 'max:255'],
            'stops.*.minutes' => [
                'required', 'integer', 'min:0', 'max:' . TechnicianAvailability::MAX_DURATION_MINUTES,
            ],
            'stops.*.urgent' => ['required', 'boolean'],
            'stops.*.before' => ['nullable', 'date_format:H:i', 'after:workday.start'],
            'start' => ['nullable', 'array', 'prohibits:start_user_id'],
            'start.lat' => ['required_with:start', 'numeric', 'between:-90,90'],
            'start.lon' => ['required_with:start', 'numeric', 'between:-180,180'],
            'start_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('plannable', true)->whereNull('deleted_at'),
            ],
            'workday' => ['required', 'array'],
            'workday.start' => ['required', 'date_format:H:i'],
            'workday.end' => ['required', 'date_format:H:i', 'after:workday.start'],
        ];
    }

    public function messages(): array
    {
        $nothing_chosen = 'Klik eerst een of meer adressen op de kaart aan.';
        $unknown_place = 'Van een van de adressen zijn de coördinaten niet bekend.';
        $workday = 'Vul de werkdag in als begin- en eindtijd, bijvoorbeeld 08:00 tot 17:00.';

        return [
            'stops.required' => $nothing_chosen,
            'stops.min' => $nothing_chosen,
            'stops.max' => 'Een route kan hoogstens :max adressen hebben.',
            'stops.*.lat.*' => $unknown_place,
            'stops.*.lon.*' => $unknown_place,
            'stops.*.minutes.*' => 'Vul bij elk adres de tijd op locatie in, van 0 tot '
                . TechnicianAvailability::MAX_DURATION_MINUTES . ' minuten.',
            'stops.*.before.after' => 'Een vóór-tijd moet na het begin van de werkdag liggen.',
            'stops.*.before.*' => 'Vul een vóór-tijd in als uur en minuten, bijvoorbeeld 10:00.',
            'start.prohibits' => 'Kies één startpunt: een plek of iemands huis.',
            'start.*' => 'Het startpunt is geen plek op de kaart.',
            'start_user_id.*' => 'Vanaf het huis van die persoon kan geen route meer beginnen.',
            'workday.required' => $workday,
            'workday.start.*' => $workday,
            'workday.end.after' => 'De werkdag moet eindigen na het begin.',
            'workday.end.*' => $workday,
        ];
    }
}
