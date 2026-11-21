<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceJobEmailed;
use App\Domain\Signals\ServiceOrders\ServiceOrderEmailed;
use App\Models\ServiceJob;

/**
 * Whatever has gone out to the customer is marked as sent: the werkbon, and its
 * keuringen when they went along. The mail itself is the sender's; this flag is
 * only what the lists show afterwards.
 */
class MarkSentToCustomer
{
    public function handleOrderEmailed(ServiceOrderEmailed $signal): void
    {
        $service_order = $signal->service_order;

        if (!$service_order->sent_to_customer) {
            $service_order->update(['sent_to_customer' => true]);
        }

        if ($signal->with_jobs) {
            $service_order->serviceJobs->each(fn (ServiceJob $job) => $this->markJob($job));
        }
    }

    public function handleJobEmailed(ServiceJobEmailed $signal): void
    {
        $this->markJob($signal->service_job);
    }

    private function markJob(ServiceJob $job): void
    {
        if (!$job->sent_to_customer) {
            $job->update(['sent_to_customer' => true]);
        }
    }
}
