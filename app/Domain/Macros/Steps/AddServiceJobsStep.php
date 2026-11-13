<?php

namespace App\Domain\Macros\Steps;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Domain\Macros\AnswerKind;
use App\Domain\Macros\Asked;
use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroStep;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;

/**
 * Keuringen for the customer's active machines, all of them or those at one
 * location. A macro serves every customer, so a location can only be asked, not
 * fixed. Machines that already have a keuring on the werkbon are skipped, and with
 * none left the run is refused, as the button on the werkbon refuses it.
 */
class AddServiceJobsStep implements MacroStep
{
    public function __construct(private readonly AddServiceJobsAction $add) {}

    public static function type(): string
    {
        return 'add_service_jobs';
    }

    public function rules(): array
    {
        return [
            'location' => ['required', 'in:all'],
        ];
    }

    public function askable(): array
    {
        return ['location' => AnswerKind::location];
    }

    public function asks(array $step): array
    {
        return Asked::in($step, $this->askable());
    }

    public function describe(array $step): string
    {
        $location = $step['location'] ?? 'all';

        return 'Keuringen toevoegen: '
            . (Asked::is($location) ? 'locatie ' . Asked::quoted($location['ask']) : 'alle machines') . '.';
    }

    public function allows(User $user): bool
    {
        return $user->can('create', ServiceJob::class);
    }

    public function run(ServiceOrder $order, array $step, MacroRun $run): void
    {
        $location = Asked::value($step, 'location', $run);

        $this->add->forCustomerMachines($order, $location === 'all' ? null : (int) $location);
    }
}
