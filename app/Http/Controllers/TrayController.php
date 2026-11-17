<?php

namespace App\Http\Controllers;

use App\Domain\Tray\Tray;
use App\Domain\Tray\TrayKinds;
use App\Http\Requests\Tray\TrayClearRequest;
use App\Http\Requests\Tray\TrayIndexRequest;
use App\Http\Requests\Tray\TrayItemDestroyRequest;
use App\Http\Requests\Tray\TrayItemUpdateRequest;
use App\Http\Requests\Tray\TrayPasteRequest;
use App\Http\Requests\Tray\TrayStoreRequest;
use App\Http\Requests\Tray\TrayUndoRequest;
use App\Models\TrayItem;
use Illuminate\Http\JsonResponse;

/**
 * The bakje, for the screens. Every answer carries the bakje as it now is, so
 * the screen never has to work out for itself what a change did to it.
 */
class TrayController extends Controller
{
    public function __construct(private Tray $tray) {}

    /** The bakje, and what each kind of thing on it is called and where it can go, so no page has to know. */
    public function index(TrayIndexRequest $request): JsonResponse
    {
        return response()->json([
            'items' => $this->tray->items($request->user()),
            'kinds' => TrayKinds::forScreens(),
        ]);
    }

    public function store(TrayStoreRequest $request): JsonResponse
    {
        $this->tray->place($request->user(), $request->validated('kind'), $request->validated('ids'));

        return response()->json(['items' => $this->tray->items($request->user())]);
    }

    public function update(TrayItemUpdateRequest $request, TrayItem $tray_item): JsonResponse
    {
        $tray_item->update(['pinned' => $request->boolean('pinned')]);

        return response()->json(['items' => $this->tray->items($request->user())]);
    }

    public function destroy(TrayItemDestroyRequest $request, TrayItem $tray_item): JsonResponse
    {
        $tray_item->delete();

        return response()->json(['items' => $this->tray->items($request->user())]);
    }

    public function clear(TrayClearRequest $request): JsonResponse
    {
        $request->user()->trayItems()->delete();

        return response()->json(['items' => []]);
    }

    public function paste(TrayPasteRequest $request): JsonResponse
    {
        return response()->json($this->tray->paste(
            $request->user(),
            $request->validated('item_ids'),
            $request->validated('target'),
            $request->validated('answer'),
            $request->boolean('remove', true),
        ));
    }

    public function undo(TrayUndoRequest $request): JsonResponse
    {
        return response()->json($this->tray->undo($request->user(), $request->validated('undo')));
    }
}
