<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportMappingDestroyRequest;
use App\Http\Requests\ImportMappingStoreRequest;
use App\Models\ImportMapping;
use App\Models\User;

class ImportMappingController extends Controller
{
    public function store(ImportMappingStoreRequest $request)
    {
        $mapping = ImportMapping::firstOrNew(['kind' => $request->importKind(), 'name' => $request->validated('name')]);
        $settings = $request->settings();

        if ($mapping->exists) {
            $settings = $this->keepWhatIsHidden($settings, $mapping, $request->user());
        }

        $mapping->fill([
            'supplier_id' => $settings['supplier_id'],
            'settings' => $settings,
        ])->save();

        return back()->with('success', 'Koppeling ‘' . $mapping->name . '’ opgeslagen.');
    }

    public function destroy(ImportMappingDestroyRequest $request, ImportMapping $import_mapping)
    {
        $import_mapping->delete();

        return back()->with('success', 'Koppeling ‘' . $import_mapping->name . '’ verwijderd.');
    }

    /**
     * Someone who may not see prices cannot map them, but a mapping they save
     * over keeps the price columns and the markup someone else chose.
     */
    private function keepWhatIsHidden(array $settings, ImportMapping $mapping, User $user): array
    {
        $hidden = array_flip($mapping->kind->target()->hiddenFieldKeys($user));

        if ($hidden === []) {
            return $settings;
        }

        $previous = $mapping->settings;
        $settings['columns'] += array_intersect_key($previous['columns'] ?? [], $hidden);
        $settings['update'] += array_intersect_key($previous['update'] ?? [], $hidden);
        $settings['markup_percent'] ??= $previous['markup_percent'] ?? null;

        return $settings;
    }
}
