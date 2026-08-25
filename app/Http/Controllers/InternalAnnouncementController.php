<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Announcements\AnnouncementAcknowledged;
use App\Domain\Signals\Signals;
use App\Http\Requests\InternalAnnouncementAcknowledgeRequest;
use App\Http\Requests\InternalAnnouncementDestroyRequest;
use App\Http\Requests\InternalAnnouncementReadRequest;
use App\Http\Requests\InternalAnnouncementStoreRequest;
use App\Http\Requests\InternalAnnouncementUpdateRequest;
use App\Models\InternalAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InternalAnnouncementController extends Controller
{
    public function index(InternalAnnouncementReadRequest $request)
    {
        return inertia('InternalAnnouncements/IndexPage', [
            'announcements' => InternalAnnouncement::query()
                ->withCount([
                    'recipients as recipient_count',
                    'recipients as acknowledged_count' => fn (Builder $query) => $query
                        ->whereNotNull('userables.acknowledged_at'),
                ])
                ->latest()
                ->get(),
            'users' => $request->user()->can('create', InternalAnnouncement::class) ? $this->userOptions() : [],
        ]);
    }

    public function show(InternalAnnouncementReadRequest $request, InternalAnnouncement $internalannouncement)
    {
        return inertia('InternalAnnouncements/ShowPage', [
            'announcement' => $internalannouncement,
            'recipients' => $internalannouncement->recipientRoster(),
            'users' => $request->user()->can('update', $internalannouncement) ? $this->userOptions() : [],
            'activities' => $internalannouncement->activities()
                ->visibleTo($request->user())
                ->with('user')
                ->latest()
                ->get(),
        ]);
    }

    public function store(InternalAnnouncementStoreRequest $request)
    {
        $validated = $request->validated();

        $announcement = DB::transaction(function () use ($validated) {
            $announcement = InternalAnnouncement::create($validated);
            $announcement->syncRecipients($this->audienceFrom($validated));

            return $announcement;
        });

        return redirect()
            ->route('internalannouncements.show', $announcement)
            ->with('success', 'Aankondiging verstuurd.');
    }

    /**
     * De doelgroep wordt als paar opgeslagen: "naar iedereen" en de lijst namen
     * horen bij elkaar en zeggen los van elkaar niets. Komt de schakelaar niet
     * mee, dan gaat deze PATCH ergens anders over en blijven de ontvangers waar
     * ze zijn.
     */
    public function update(InternalAnnouncementUpdateRequest $request, InternalAnnouncement $internalannouncement)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($internalannouncement, $validated) {
            $internalannouncement->update($validated);

            if (array_key_exists('is_for_everyone', $validated)) {
                $internalannouncement->syncRecipients($this->audienceFrom($validated));
            }
        });

        return redirect()->back()->with('success', 'Aankondiging bijgewerkt.');
    }

    public function destroy(InternalAnnouncementDestroyRequest $request, InternalAnnouncement $internalannouncement)
    {
        DB::transaction(fn () => $internalannouncement->delete());

        return redirect()
            ->route('internalannouncements.index')
            ->with('success', 'Aankondiging verwijderd.');
    }

    /**
     * Geen melding terug: de balk die weggaat is het antwoord. Het signaal gaat
     * alleen af als er echt iets veranderde, zodat een dubbele klik geen tweede
     * regel op de tijdlijn oplevert.
     */
    public function acknowledge(
        InternalAnnouncementAcknowledgeRequest $request,
        InternalAnnouncement $internalannouncement
    ) {
        DB::transaction(function () use ($internalannouncement, $request) {
            if ($internalannouncement->acknowledgeFor($request->user())) {
                Signals::dispatch(new AnnouncementAcknowledged($internalannouncement, $request->user()));
            }
        });

        return redirect()->back();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function audienceFrom(array $validated): array
    {
        if ($validated['is_for_everyone'] ?? false) {
            return User::query()->pluck('id')->all();
        }

        return $validated['user_ids'] ?? [];
    }

    /** @return Collection<int, User> */
    private function userOptions(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }
}
