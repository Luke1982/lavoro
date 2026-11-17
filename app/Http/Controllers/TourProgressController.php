<?php

namespace App\Http\Controllers;

use App\Domain\Tours\TourCatalog;
use App\Http\Requests\Tours\TourProgressShowRequest;
use App\Http\Requests\Tours\TourProgressUpdateRequest;
use App\Models\TourProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Where somebody is with a rondleiding, for every screen they use. Only ever
 * their own: the person is the one signed in, never a number the screen sends.
 */
class TourProgressController extends Controller
{
    public function show(TourProgressShowRequest $request, TourCatalog $catalog, string $tour): JsonResponse
    {
        $progress = TourProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('tour', $tour)
            ->first() ?? new TourProgress;

        return response()->json([...$progress->toScreen(), 'recorded' => $catalog->recordedChapters($tour)]);
    }

    /**
     * Two calls can cross — the picker closing while the first chapter is marked
     * seen — so the row is created once and then changed under a lock, and a
     * chapter seen earlier is never forgotten by a later call that did not name it.
     */
    public function update(TourProgressUpdateRequest $request, string $tour): JsonResponse
    {
        $user_id = $request->user()->id;

        $progress = DB::transaction(function () use ($request, $tour, $user_id) {
            TourProgress::createOrFirst(['user_id' => $user_id, 'tour' => $tour]);

            $progress = TourProgress::query()
                ->where('user_id', $user_id)
                ->where('tour', $tour)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->boolean('started') && $progress->started_at === null) {
                $progress->started_at = now();
            }

            $seen = $progress->seen ?? [];
            foreach ($request->validated('seen') ?? [] as $entry) {
                $seen[$entry['chapter']] = (int) $entry['version'];
            }
            $progress->seen = $seen;
            $progress->save();

            return $progress;
        });

        return response()->json($progress->toScreen());
    }
}
