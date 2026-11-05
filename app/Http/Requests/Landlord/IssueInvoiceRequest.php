<?php

namespace App\Http\Requests\Landlord;

use App\Models\Tenant;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Today's period, or one that was skipped or whose invoice was credited: only
 * those, so a period that is invoiced cannot be invoiced twice from here.
 */
class IssueInvoiceRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'period_start' => ['nullable', 'date_format:Y-m-d', Rule::in($this->openPeriods())],
        ];
    }

    public function messages(): array
    {
        return ['period_start.in' => 'Die periode is al gefactureerd.'];
    }

    public function period(): ?CarbonImmutable
    {
        $start = $this->validated('period_start');

        return $start ? CarbonImmutable::parse($start) : null;
    }

    /** @return array<int, string> */
    private function openPeriods(): array
    {
        $tenant = Tenant::on('central')->findOrFail($this->route('tenant'));

        return collect((new Invoicer($tenant))->unbilledPeriods())
            ->map(fn (array $period) => $period['start']->toDateString())
            ->all();
    }
}
