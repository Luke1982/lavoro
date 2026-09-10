<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Clears out the folders of customers that no longer exist.
 *
 * A customer's folder is named after its id: storage/tenant-<id>. If one is
 * left behind after the customer is gone, nobody can reach it any more: no
 * login points at it. The doctor keeps reporting it until it is gone.
 */
class TenancyPruneStorage extends Command
{
    protected $signature = 'tenancy:prune-storage
        {folder? : a single folder, with or without tenant- in front; leave out for all}
        {--force : do not ask for confirmation}';

    protected $description = 'Removes the storage folders of customers that no longer exist';

    public function handle(): int
    {
        $ids = Tenant::on('central')->pluck('id');

        $orphans = collect(File::directories(storage_path()))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => str_starts_with($name, 'tenant-'))
            ->reject(fn (string $name) => $ids->contains(substr($name, strlen('tenant-'))))
            ->values();

        if ($folder = $this->argument('folder')) {
            $folder = str_starts_with($folder, 'tenant-') ? $folder : 'tenant-' . $folder;

            if (!$orphans->contains($folder)) {
                $this->error(File::isDirectory(storage_path($folder))
                    ? "{$folder} belongs to a customer that still exists and is not removed."
                    : 'Folder does not exist: ' . storage_path($folder));

                return self::FAILURE;
            }

            $orphans = collect([$folder]);
        }

        if ($orphans->isEmpty()) {
            $this->info('Nothing to clear.');

            return self::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $files = collect(File::allFiles(storage_path($orphan)));

            $this->line(sprintf('%s -- %d file(s), %s',
                $orphan, $files->count(), $this->humanSize($files->sum(fn ($file) => $file->getSize()))));
        }

        if (!$this->option('force') && !$this->confirm('Delete permanently?', false)) {
            $this->line('Nothing done.');

            return self::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            File::deleteDirectory(storage_path($orphan));
            $this->info($orphan . ' removed');
        }

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }

            $bytes /= 1024;
        }

        return $bytes . ' B';
    }
}
