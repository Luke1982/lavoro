<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentDestroyRequest;
use App\Http\Requests\DocumentStoreRequest;
use App\Http\Requests\DocumentUpdateRequest;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function store(DocumentStoreRequest $request)
    {
        $documentable_record = new ($request->documentable_type);
        $documentable_record = $documentable_record->find($request->documentable_id);
        $model_name = strtolower(class_basename($documentable_record));

        $created_documents = [];

        foreach ($request->file('documents') as $document_file) {
            $path = $document_file->store('uploaded/' . $model_name . '/' . $request->documentable_id . '/documents', 'public');

            $document = Document::create([
                'name' => $document_file->getClientOriginalName(),
                'path' => $path,
            ]);

            $documentable_record->documents()->attach($document->id, [
                'internal' => $request->boolean('internal', false),
            ]);
            $created_documents[] = $document;
        }

        return back()->with([
            'success' => 'Document(en) geüpload.',
            'extra' => json_encode($created_documents),
        ]);
    }

    public function update(DocumentUpdateRequest $request, Document $document)
    {
        $document->update($request->validated());

        return back()->with('success', 'Document bijgewerkt.');
    }

    public function destroy(DocumentDestroyRequest $request, Document $document)
    {
        $link = DB::table('documentables')->where('document_id', $document->id)->first();

        if ($link) {
            $documentable_record = (new ($link->documentable_type))->find($link->documentable_id);
            if ($documentable_record && method_exists($documentable_record, 'logActivity')) {
                $documentable_record->logActivity(sprintf('Document verwijderd: %s', $document->name));
            }
        }

        Storage::disk('public')->delete($document->path);
        $document->delete();

        return back()->with('success', 'Document verwijderd.');
    }

    public function download(Document $document)
    {
        abort_unless(auth()->user()->hasPermission('document.see'), 403);

        return Storage::disk('public')->download($document->path, $document->name);
    }
}
