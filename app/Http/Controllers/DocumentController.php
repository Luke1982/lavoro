<?php

namespace App\Http\Controllers;

use App\Actions\Documents\AttachDocumentAction;
use App\Domain\Signals\Attachments\DocumentChanged;
use App\Domain\Signals\Attachments\DocumentRemoved;
use App\Domain\Signals\Signals;
use App\Http\Requests\DocumentBulkCategoryRequest;
use App\Http\Requests\DocumentBulkDestroyRequest;
use App\Http\Requests\DocumentDestroyRequest;
use App\Http\Requests\DocumentStoreRequest;
use App\Http\Requests\DocumentUpdateRequest;
use App\Http\Requests\DocumentViewRequest;
use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function store(DocumentStoreRequest $request)
    {
        $record_class = Document::attachableType($request->documentable_type);
        $documentable_record = $record_class::findOrFail($request->documentable_id);
        $created_documents = app(AttachDocumentAction::class)->executeMany(
            $documentable_record,
            $request->file('documents'),
            $request->document_category_id,
            $request->user()->id,
            $request->boolean('internal', false),
        );

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
        $this->change($document, $request->validated());

        return back()->with('success', 'Document bijgewerkt.');
    }

    public function destroy(DocumentDestroyRequest $request, Document $document)
    {
        $this->purge($document);

        return back()->with('success', 'Document verwijderd.');
    }

    public function bulkCategory(DocumentBulkCategoryRequest $request)
    {
        [$allowed, $skipped] = $request->documents();

        Signals::asBatch(fn () => DB::transaction(fn () => $allowed->each(fn (Document $document) => $this->change($document, [
            'document_category_id' => $request->document_category_id,
        ]))));

        return back()->with('success', 'Categorie toegewezen aan ' . $allowed->count() . ' document(en).' . self::skippedNote($skipped, 'wijzigen'));
    }

    public function bulkDestroy(DocumentBulkDestroyRequest $request)
    {
        [$allowed, $skipped] = $request->documents();

        Signals::asBatch(fn () => DB::transaction(fn () => $allowed->each(fn (Document $document) => $this->purge($document))));

        return back()->with('success', $allowed->count() . ' document(en) verwijderd.' . self::skippedNote($skipped, 'verwijderen'));
    }

    /** Which documents a bulk action left alone, by title, so nobody thinks they were done. */
    private static function skippedNote(Collection $skipped, string $verb): string
    {
        return $skipped->isEmpty()
            ? ''
            : ' Overgeslagen, want die mag je niet ' . $verb . ': ' . $skipped->map(fn (Document $document) => '"' . ($document->title ?: $document->name) . '"')->implode(', ') . '.';
    }

    /**
     * Saves a new title or category and tells the record the document hangs from.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function change(Document $document, array $attributes): void
    {
        $before = $document->only(array_keys($attributes));
        $document->update($attributes);

        $record = $document->attachedRecord();

        if ($record !== null && $document->wasChanged()) {
            Signals::dispatch(new DocumentChanged($record, $document, $before));
        }
    }

    /**
     * Drops the row and announces it on whatever the document hangs off; the file
     * goes once that has committed. A document hanging from nothing is announced
     * on itself so that its file still goes, and that announcement writes nothing.
     */
    private function purge(Document $document): void
    {
        $record = $document->attachedRecord() ?? $document;

        $document->delete();

        Signals::dispatch(new DocumentRemoved($record, $document->id, $document->name, $document->path));
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
