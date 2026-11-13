<?php

namespace App\Actions\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceJobAdded;
use App\Domain\Signals\Signals;
use App\Enums\ServiceJobOutcomes;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Services\BundleServiceJobCreator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts keuringen on a werkbon for the given machines: one per machine the werkbon
 * does not have yet, a combined keuring for each of their parts, and each one on
 * the timeline. Adding one machine, many at once, or a new werkbon with machines
 * all go through here, so they all behave the same.
 */
class AddServiceJobsAction
{
    public function __construct(private readonly BundleServiceJobCreator $bundles) {}

    /**
     * Keuringen for the werkbon customer's active machines, or those at one of its
     * locations. Refused when the werkbon has no customer, or when every one of
     * those machines already has a keuring on it.
     *
     * @return array{jobs: Collection<int, ServiceJob>, parts: array<int, ServiceJob>}
     */
    public function forCustomerMachines(ServiceOrder $order, ?int $location_id): array
    {
        throw_if(!$order->customer, Refusal::class, 'Deze werkbon heeft geen klant.');

        $machines = $order->customer->activeAssets()
            ->with(['product.brand', 'product.productType'])
            ->when($location_id, fn ($query) => $query->where('location_id', $location_id))
            ->get();

        $added = $this->execute($order, $machines);

        throw_if(
            $added['jobs']->isEmpty(),
            Refusal::class,
            'Er zijn geen machines meer zonder keuring op deze werkbon.',
        );

        return $added;
    }

    /**
     * @param  iterable<Asset>  $machines
     * @param  array<string, mixed>  $attributes  Fields every new keuring starts with; no outcome yet by default.
     * @return array{jobs: Collection<int, ServiceJob>, parts: array<int, ServiceJob>}
     */
    public function execute(ServiceOrder $order, iterable $machines, array $attributes = []): array
    {
        $present = $order->servicejobs()->pluck('asset_id');
        $machines = collect($machines)->reject(fn (Asset $machine) => $present->contains($machine->id))->values();

        return DB::transaction(function () use ($order, $machines, $attributes) {
            $jobs = $machines->map(fn (Asset $machine) => ServiceJob::create([
                'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
                ...$attributes,
                'asset_id' => $machine->id,
                'service_order_id' => $order->id,
            ])->setRelation('asset', $machine));

            $parts = $this->bundles->createForAll($jobs);

            foreach ($jobs as $job) {
                Signals::dispatch(new ServiceJobAdded($order, $job->asset));
            }

            foreach ($parts as $part) {
                Signals::dispatch(new ServiceJobAdded($order, $part->asset, combined: true));
            }

            return ['jobs' => $jobs, 'parts' => $parts];
        });
    }
}
