<?php

namespace App\Jobs;

use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSupplierImportJob implements ShouldQueue
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
                $supplier = Supplier::where('name', $data['name'])->first();
                if ($supplier) {
                    $supplier->update($data);
                } else {
                    Log::warning('SupplierImport: no supplier found for update', ['name' => $data['name']]);
                }
            } else {
                Supplier::create($data);
            }
        }
    }
}
