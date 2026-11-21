<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Models\User;
use App\Support\ActingAs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSupplierImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A plain property with a default rather than a promoted one: a job queued
     * before it existed is unserialized without it, and must still run.
     */
    public ?int $user_id = null;

    public function __construct(public array $rows, ?int $user_id = null)
    {
        $this->user_id = $user_id;
    }

    /**
     * Runs as the person who imported the list: the history of every supplier it
     * makes or changes reads its actor from the session, which a worker does not
     * have.
     */
    public function handle(): void
    {
        ActingAs::run($this->user_id ? User::find($this->user_id) : null, fn () => $this->import());
    }

    private function import(): void
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
