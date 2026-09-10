<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\InvoiceMailer;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class IssueInvoices extends Command
{
    protected $signature = 'invoices:issue
        {--tenant= : Alleen deze tenant}
        {--on= : Doe alsof het deze datum is (jjjj-mm-dd)}
        {--mail : Ook meteen versturen}
        {--dry-run : Alleen tonen wat er zou gebeuren}';

    protected $description = 'Creates the invoices whose period has started';

    public function handle(InvoiceMailer $mailer): int
    {
        $on = $this->option('on') ? CarbonImmutable::parse($this->option('on')) : CarbonImmutable::now();

        $tenants = Tenant::on('central')
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $issued = 0;
        $mailed = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $invoicer = new Invoicer($tenant);

            if (!$invoicer->isDue($on)) {
                continue;
            }

            if ($this->option('dry-run')) {
                [$start, $end] = $invoicer->periodFor($on);
                $this->line(sprintf(
                    '%s: %s t/m %s, € %s',
                    $tenant->name,
                    $start->format('d-m-Y'),
                    $end->format('d-m-Y'),
                    number_format($invoicer->preview($on)['gross_cents'] / 100, 2, ',', '.'),
                ));
                $issued++;

                continue;
            }

            /**
             * Caught per tenant: one customer with a broken amount or a
             * refusing mail server must not stop the rest of the round.
             */
            try {
                $invoice = $invoicer->issue($on);
            } catch (\Throwable $e) {
                $this->error($tenant->name . ': invoicing failed — ' . $e->getMessage());
                report($e);
                $failed++;

                continue;
            }

            $issued++;
            $this->info($tenant->name . ': ' . $invoice->number . ' — € '
                . number_format($invoice->gross_cents / 100, 2, ',', '.'));

            /**
             * Sending does not happen by itself. The invoices are created and
             * stay put until someone has looked at them; only the button in the
             * admin panel sends them out.
             */
            if (!$this->option('mail')) {
                continue;
            }

            if ($mailer->send($invoice, $tenant)) {
                $mailed++;

                continue;
            }

            $failed++;
            $this->warn('  sending failed: ' . $invoice->mail_error);
        }

        $this->line(sprintf('%d invoiced, %d sent, %d failed.', $issued, $mailed, $failed));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
