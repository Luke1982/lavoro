<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentCategoryDestroyRequest;
use App\Http\Requests\DocumentCategoryStoreRequest;
use App\Http\Requests\DocumentCategoryUpdateRequest;
use App\Models\DocumentCategory;

class DocumentCategoryController extends Controller
{
    public function store(DocumentCategoryStoreRequest $request)
    {
        DocumentCategory::create([
            ...$request->validated(),
            'order' => (int) DocumentCategory::max('order') + 1,
        ]);

        return back()->with('success', 'Categorie aangemaakt.');
    }

    public function update(DocumentCategoryUpdateRequest $request, DocumentCategory $documentcategory)
    {
        $documentcategory->update($request->validated());

        return back()->with('success', 'Categorie bijgewerkt.');
    }

    public function destroy(DocumentCategoryDestroyRequest $request, DocumentCategory $documentcategory)
    {
        $documentcategory->delete();

        return back()->with('success', 'Categorie verwijderd.');
    }
}
