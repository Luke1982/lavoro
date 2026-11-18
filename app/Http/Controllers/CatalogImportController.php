<?php

namespace App\Http\Controllers;

use App\Domain\Imports\CatalogPlan;
use App\Domain\Imports\CatalogPreview;
use App\Domain\Imports\CatalogRows;
use App\Domain\Imports\ImportSettings;
use App\Domain\Imports\ImportSheet;
use App\Enums\CatalogImportKind;
use App\Enums\CatalogImportStatus;
use App\Exceptions\Refusal;
use App\Http\Requests\CatalogImportConfirmRequest;
use App\Http\Requests\CatalogImportDestroyRequest;
use App\Http\Requests\CatalogImportMapRequest;
use App\Http\Requests\CatalogImportReadRequest;
use App\Http\Requests\CatalogImportUploadRequest;
use App\Jobs\RunCatalogImportJob;
use App\Models\CatalogImport;
use App\Models\ImportMapping;
use App\Models\Supplier;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reading a supplier's spreadsheet into the materials or the products: upload,
 * map the columns, check what it will do, confirm. The writing happens in the
 * background; this screen follows it until it is done.
 */
class CatalogImportController extends Controller
{
    /** An upload nobody went on with is thrown away the next time its owner uploads. */
    private const ABANDONED_AFTER_HOURS = 24;

    public function store(CatalogImportUploadRequest $request, CatalogImportKind $kind)
    {
        $this->forgetAbandoned($request->user());

        $file = $request->file('file');
        $path = $file->storeAs(
            'imports',
            Str::uuid() . '.' . strtolower($file->getClientOriginalExtension()),
            'local',
        );

        $import = CatalogImport::create([
            'kind' => $kind,
            'user_id' => $request->user()->id,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => CatalogImportStatus::uploaded,
        ]);

        try {
            ImportSheet::open($import->filePath());
        } catch (Refusal $refusal) {
            $import->forgetFiles();
            $import->delete();

            throw $refusal;
        }

        return redirect($import->path());
    }

    public function show(CatalogImportReadRequest $request, CatalogImportKind $kind, CatalogImport $catalog_import)
    {
        $this->ensureKind($catalog_import, $kind);

        $page = [
            'catalogImport' => $catalog_import->toPageArray(),
            'kind' => $kind->toPageArray(),
        ];

        if (!$catalog_import->status->isEditable()) {
            return inertia('Imports/CatalogImportPage', $page);
        }

        $settings = ImportSettings::normalize($catalog_import->settings);
        $sheet_name = $request->validated('sheet') ?? $settings['sheet'];
        $header_row = (int) ($request->validated('header_row') ?? $settings['header_row']);
        $sheets = [];
        $open = function (?string $name) use ($catalog_import, &$sheets): ImportSheet {
            return $sheets[$name ?? ''] ??= ImportSheet::open($catalog_import->filePath(), $name);
        };

        try {
            $sheet = $open($sheet_name);
        } catch (Refusal $refusal) {
            $catalog_import->forgetFiles();
            $catalog_import->delete();

            return redirect($kind->indexPath())->with('error', $refusal->getMessage());
        }

        $mappings = ImportMapping::where('kind', $kind)->latest('updated_at')->get();

        return inertia('Imports/CatalogImportPage', [
            ...$page,
            'settings' => $settings,
            'sheet' => fn () => [
                'names' => $sheet->sheetNames(),
                'name' => $sheet->sheetName(),
                'header_row' => $header_row,
                'columns' => $sheet->columns($header_row),
            ],
            'fields' => $kind->target()->fieldOptions($request->user()),
            'matchKeys' => $kind->target()->matchKeyOptions(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'mappings' => $mappings->map->toOption()->values(),
            'suggestedMappingId' => $catalog_import->status === CatalogImportStatus::uploaded
                ? $this->suggestedMapping($mappings, $open)
                : null,
            'preview' => $catalog_import->status === CatalogImportStatus::mapped
                ? fn () => CatalogPreview::build($catalog_import, $open($settings['sheet']))
                : null,
        ]);
    }

    public function map(CatalogImportMapRequest $request, CatalogImportKind $kind, CatalogImport $catalog_import)
    {
        $this->ensureKind($catalog_import, $kind);

        if (!$catalog_import->status->isEditable()) {
            throw new Refusal('Deze import is al bevestigd en kan niet meer worden aangepast.');
        }

        $settings = $request->settings();

        $catalog_import->update([
            'settings' => $settings,
            'supplier_id' => $settings['supplier_id'],
            'status' => CatalogImportStatus::mapped,
        ]);

        return redirect($catalog_import->path());
    }

    public function confirm(CatalogImportConfirmRequest $request, CatalogImportKind $kind, CatalogImport $catalog_import)
    {
        $this->ensureKind($catalog_import, $kind);

        $plan = CatalogPlan::for($catalog_import);
        $rows = CatalogRows::read(ImportSheet::open($catalog_import->filePath(), $plan->settings()['sheet']), $plan);

        $claimed = CatalogImport::whereKey($catalog_import->id)
            ->where('status', CatalogImportStatus::mapped)
            ->update(['status' => CatalogImportStatus::queued]);

        if ($claimed === 0) {
            throw new Refusal('Deze import is al bevestigd.');
        }

        Storage::disk('local')->put($catalog_import->rowsPath(), json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE));

        $catalog_import->refresh()->update([
            'total' => count($rows),
            'processed' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'skipped_count' => 0,
            'failures' => null,
            'error' => null,
        ]);

        RunCatalogImportJob::dispatch($catalog_import->id);

        return redirect($catalog_import->path());
    }

    public function destroy(CatalogImportDestroyRequest $request, CatalogImportKind $kind, CatalogImport $catalog_import)
    {
        $this->ensureKind($catalog_import, $kind);

        if ($catalog_import->status->isBusy()) {
            throw new Refusal('Deze import loopt al en kan niet meer worden geannuleerd.');
        }

        $catalog_import->forgetFiles();
        $catalog_import->delete();

        return redirect($kind->indexPath());
    }

    /**
     * The saved mapping whose columns are all in this file, the most recently
     * used first: the next list from the same supplier opens already mapped.
     *
     * @param  Collection<int, ImportMapping>  $mappings
     * @param  Closure(?string): ImportSheet  $open
     */
    private function suggestedMapping(Collection $mappings, Closure $open): ?int
    {
        foreach ($mappings as $mapping) {
            $settings = ImportSettings::normalize($mapping->settings);
            $names = array_column($open($settings['sheet'])->columns($settings['header_row']), 'name');

            if ($settings['columns'] !== [] && array_diff($settings['columns'], $names) === []) {
                return $mapping->id;
            }
        }

        return null;
    }

    /** /products/imports/5 for an import of materials is not a page that exists. */
    private function ensureKind(CatalogImport $catalog_import, CatalogImportKind $kind): void
    {
        abort_unless($catalog_import->kind === $kind, 404);
    }

    private function forgetAbandoned(User $user): void
    {
        CatalogImport::where('user_id', $user->id)
            ->whereIn('status', [CatalogImportStatus::uploaded, CatalogImportStatus::mapped])
            ->where('updated_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
            ->get()
            ->each(function (CatalogImport $import) {
                $import->forgetFiles();
                $import->delete();
            });
    }
}
