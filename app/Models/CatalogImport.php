<?php

namespace App\Models;

use App\Enums\CatalogImportKind;
use App\Enums\CatalogImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One spreadsheet on its way into the materials or the products: the uploaded
 * file, the column choices made for it, and once it runs how far it got. The
 * file lives on the local disk until the import is finished or thrown away.
 *
 * @property CatalogImportKind $kind
 * @property CatalogImportStatus $status
 * @property array|null $settings
 * @property array|null $failures
 */
class CatalogImport extends Model
{
    protected $fillable = [
        'kind',
        'user_id',
        'supplier_id',
        'file_path',
        'original_name',
        'settings',
        'status',
        'total',
        'processed',
        'created_count',
        'updated_count',
        'unchanged_count',
        'skipped_count',
        'failures',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CatalogImportKind::class,
            'status' => CatalogImportStatus::class,
            'settings' => 'array',
            'failures' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Where the import screen is: under the list it fills, so the menu and the tab say which. */
    public function path(): string
    {
        return $this->kind->indexPath() . '/imports/' . $this->id;
    }

    public function filePath(): string
    {
        return Storage::disk('local')->path($this->file_path);
    }

    /** The rows as they were read at confirmation, which is what the job works through. */
    public function rowsPath(): string
    {
        return 'imports/' . $this->id . '-rows.json';
    }

    public function forgetFiles(): void
    {
        Storage::disk('local')->delete([$this->file_path, $this->rowsPath()]);
    }

    public function toPageArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path(),
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'supplier_name' => $this->supplier?->name,
            'total' => $this->total,
            'processed' => $this->processed,
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'unchanged_count' => $this->unchanged_count,
            'skipped_count' => $this->skipped_count,
            'failures' => $this->failures ?? [],
            'error' => $this->error,
        ];
    }
}
