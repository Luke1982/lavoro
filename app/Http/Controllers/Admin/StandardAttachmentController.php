<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StandardAttachmentDestroyRequest;
use App\Http\Requests\StandardAttachmentReadRequest;
use App\Http\Requests\StandardAttachmentStoreRequest;
use App\Http\Requests\StandardAttachmentUpdateRequest;
use App\Models\StandardAttachment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StandardAttachmentController extends Controller
{
    public function index(StandardAttachmentReadRequest $request): Response
    {
        return Inertia::render('Admin/StandardAttachments/IndexPage', [
            'standardAttachments' => StandardAttachment::orderBy('name')->get(),
        ]);
    }

    public function store(StandardAttachmentStoreRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('uploaded/standardattachments', 'public');

        StandardAttachment::create([
            'name' => $request->input('name'),
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return redirect()->back()->with('success', 'Standaard bijlage geüpload.');
    }

    public function update(StandardAttachmentUpdateRequest $request, StandardAttachment $standard_attachment): RedirectResponse
    {
        $standard_attachment->update($request->validated());

        return redirect()->back()->with('success', 'Standaard bijlage bijgewerkt.');
    }

    /** The file is removed by a listener once the removal is committed. */
    public function destroy(StandardAttachmentDestroyRequest $request, StandardAttachment $standard_attachment): RedirectResponse
    {
        $standard_attachment->delete();

        return redirect()->back()->with('success', 'Standaard bijlage verwijderd.');
    }
}
