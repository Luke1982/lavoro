<?php

namespace App\Http\Controllers;

use App\Actions\Documents\AttachDocumentAction;
use App\Domain\Signals\Attachments\DocumentRemoved;
use App\Domain\Signals\Signals;
use App\Http\Requests\DocumentBulkCategoryRequest;
use App\Http\Requests\DocumentBulkDestroyRequest;
use App\Http\Requests\DocumentDestroyRequest;
use App\Http\Requests\DocumentStoreRequest;
use App\Http\Requests\DocumentUpdateRequest;
use App\Http\Requests\DocumentViewRequest;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function store(DocumentStoreRequest $request)
    {
        $documentable_record = new ($request->documentable_type);
        $documentable_record = $documentable_record->find($request->documentable_id);
        $created_documents = [];

        foreach ($request->file('documents') as $document_file) {
            $created_documents[] = app(AttachDocumentAction::class)->execute(
                $documentable_record,
                $document_file,
                $document_file->getClientOriginalName(),
                $request->document_category_id,
                $request->user()->id,
                $request->boolean('internal', false),
            );
        }

        if ($request->wantsJson()) {
            return response()->json(
                Document::with(['category', 'user:id,name'])
                    ->whereIn('id', collect($created_documents)->pluck('id'))
                    ->get()
            );
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
        $this->purge($document);

        return back()->with('success', 'Document verwijderd.');
    }

    public function bulkCategory(DocumentBulkCategoryRequest $request)
    {
        Document::whereIn('id', $request->ids)
            ->update(['document_category_id' => $request->document_category_id]);

        return back()->with('success', 'Categorie toegewezen.');
    }

    public function bulkDestroy(DocumentBulkDestroyRequest $request)
    {
        Document::whereIn('id', $request->ids)
            ->get()
            ->each(fn (Document $document) => $this->purge($document));

        return back()->with('success', 'Documenten verwijderd.');
    }

    /**
     * Log the removal on whatever the document hangs off, drop the file and the row.
     */
    private function purge(Document $document): void
    {
        $link = DB::table('documentables')->where('document_id', $document->id)->first();

        if ($link) {
            $documentable_record = (new ($link->documentable_type))->find($link->documentable_id);
            if ($documentable_record) {
                Signals::dispatch(new DocumentRemoved($documentable_record, $document->id, $document->name));
            }
        }

        Storage::disk('public')->delete($document->path);
        $document->delete();
    }

    public function download(DocumentViewRequest $request, Document $document)
    {
        return Storage::disk('public')->download($document->path, $document->name);
    }

    /**
     * Same bytes as download, served inline so the browser can render them in a
     * tab. Exists so the UI never has to link at /storage directly: that path is
     * served by the webserver, outside every middleware and permission check.
     */
    public function preview(DocumentViewRequest $request, Document $document)
    {
        return Storage::disk('public')->response($document->path, $document->name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
