<?php

namespace App\Services;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageQuota
{
    public function usedBytes(): int
    {
        return $this->fileBytes() + $this->databaseBytes();
    }

    public function fileBytes(): int
    {
        return (int) GeneralSetting::get('storage_used_bytes', 0);
    }

    /**
     * Vijf minuten gecachet: dit draait bij elke upload-validatie en het getal
     * beweegt traag. De cachesleutel heeft al een tenantprefix.
     */
    public function databaseBytes(): int
    {
        return Cache::remember('storage_database_bytes', 300, function () {
            $connection = DB::connection('tenant');

            $row = $connection->selectOne(
                'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                [$connection->getDatabaseName()],
            );

            return (int) ($row->bytes ?? 0);
        });
    }

    public function limitBytes(): int
    {
        return (int) tenancy()->tenant->storage_limit_gb * (1024 ** 3);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->limitBytes() - $this->usedBytes());
    }

    public function hasRoomFor(int $bytes): bool
    {
        return $this->usedBytes() + $bytes <= $this->limitBytes();
    }

    public function add(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', $this->fileBytes() + max(0, $bytes));
    }

    public function subtract(int $bytes): void
    {
        GeneralSetting::set('storage_used_bytes', max(0, $this->fileBytes() - max(0, $bytes)));
    }

    public function reconcile(): int
    {
        $total = 0;

        foreach (['public', 'local'] as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $file) {
                $total += Storage::disk($disk)->size($file);
            }
        }

        GeneralSetting::set('storage_used_bytes', $total);

        return $total;
    }
}
