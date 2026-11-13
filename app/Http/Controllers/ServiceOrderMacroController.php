<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderMacroManageRequest;
use App\Http\Requests\ServiceOrderMacroStoreUpdateRequest;
use App\Models\DocumentCategory;
use App\Models\Product;
use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklist;

/**
 * Building macros: the list of named macros, and the editor where a macro is put
 * together from blocks.
 */
class ServiceOrderMacroController extends Controller
{
    public function index(ServiceOrderMacroManageRequest $request)
    {
        return inertia('ServiceOrderMacros/IndexPage', [
            'macros' => ServiceOrderMacro::orderBy('title')->get()
                ->map(fn (ServiceOrderMacro $macro) => [
                    'id' => $macro->id,
                    'title' => $macro->title,
                    'description' => $macro->description,
                    'readable' => $macro->readable(),
                ]),
        ]);
    }

    public function create(ServiceOrderMacroManageRequest $request)
    {
        return inertia('ServiceOrderMacros/EditorPage', ['macro' => null] + $this->library());
    }

    public function edit(ServiceOrderMacroManageRequest $request, ServiceOrderMacro $serviceordermacro)
    {
        return inertia('ServiceOrderMacros/EditorPage', [
            'macro' => $serviceordermacro->only(['id', 'title', 'description', 'definition']),
        ] + $this->library());
    }

    public function store(ServiceOrderMacroStoreUpdateRequest $request)
    {
        $macro = ServiceOrderMacro::create($request->validated());

        return redirect()->route('serviceordermacros.edit', $macro)->with('success', 'Macro is opgeslagen');
    }

    public function update(ServiceOrderMacroStoreUpdateRequest $request, ServiceOrderMacro $serviceordermacro)
    {
        $serviceordermacro->update($request->validated());

        return redirect()->back()->with('success', 'Macro is opgeslagen');
    }

    public function destroy(ServiceOrderMacroManageRequest $request, ServiceOrderMacro $serviceordermacro)
    {
        $serviceordermacro->delete();

        return redirect()->route('serviceordermacros.index')->with('success', 'Macro is verwijderd');
    }

    /** What the editor's blocks pick from. */
    private function library(): array
    {
        return [
            'tasks' => ServiceOrderTask::orderBy('title')->get(['id', 'title']),
            'products' => Product::with('brand')->orderBy('model')->get()
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->display_name]),
            'checks' => ServiceOrderTaskCheck::orderBy('title')->get(['id', 'title']),
            'checklists' => ServiceOrderTaskChecklist::orderBy('title')->get(['id', 'title']),
            'documentCategories' => DocumentCategory::orderBy('order')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
