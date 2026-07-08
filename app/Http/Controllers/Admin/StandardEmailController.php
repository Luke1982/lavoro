<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StandardEmailDestroyRequest;
use App\Http\Requests\StandardEmailStoreRequest;
use App\Http\Requests\StandardEmailUpdateRequest;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StandardEmailController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/StandardEmails/IndexPage', [
            'standardEmails' => StandardEmail::with('triggers', 'standardAttachments')->orderBy('name')->get(),
            'standardAttachments' => StandardAttachment::orderBy('name')->get(['id', 'name']),
            'eventTriggers' => EventTrigger::comboBoxArray(),
            'triggerTypes' => StandardEmailTriggerType::comboBoxArray(),
            'placeholders' => StandardEmailRenderer::placeholders(),
        ]);
    }

    public function store(StandardEmailStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $triggers = $data['triggers'] ?? [];
        $attachment_ids = $data['standard_attachment_ids'] ?? [];
        unset($data['triggers'], $data['standard_attachment_ids']);

        $standard_email = StandardEmail::create($data);
        $standard_email->triggers()->createMany($triggers);
        $standard_email->standardAttachments()->sync($attachment_ids);

        return redirect()->back()->with('success', 'Standaard e-mail aangemaakt.');
    }

    public function update(StandardEmailUpdateRequest $request, StandardEmail $standard_email): RedirectResponse
    {
        $data = $request->validated();
        $triggers = $data['triggers'] ?? [];
        $attachment_ids = $data['standard_attachment_ids'] ?? [];
        unset($data['triggers'], $data['standard_attachment_ids']);

        $standard_email->update($data);
        $standard_email->triggers()->delete();
        $standard_email->triggers()->createMany($triggers);
        $standard_email->standardAttachments()->sync($attachment_ids);

        return redirect()->back()->with('success', 'Standaard e-mail bijgewerkt.');
    }

    public function destroy(StandardEmailDestroyRequest $request, StandardEmail $standard_email): RedirectResponse
    {
        $standard_email->delete();

        return redirect()->back()->with('success', 'Standaard e-mail verwijderd.');
    }
}
