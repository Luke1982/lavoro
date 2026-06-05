<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCustomerImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $rows)
    {
    }

    public function handle(): void
    {
        foreach ($this->rows as $row) {
            if (($row['fatal'] ?? false) || ($row['action'] ?? null) === 'skip') {
                continue;
            }

            $data = array_diff_key($row, array_flip(['action', 'fatal', 'warnings']));

            if (!empty($data['phone'])) {
                $data['phone'] = preg_replace('/\D+/', '', (string) $data['phone']);
            }
            if (!empty($data['postal_code'])) {
                $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
            }

            if (($row['action'] ?? null) === 'update') {
                $customer = Customer::where('name', $data['name'])->first();
                if ($customer) {
                    $customer->update($data);
                } else {
                    Log::warning('CustomerImport: no customer found for update', ['name' => $data['name']]);
                }
            } else {
                $data['snelstart_id'] = (string) Str::uuid();
                Customer::create($data);
            }
        }
    }
}
