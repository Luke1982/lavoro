<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemarkCreateRequest;
use App\Models\Event;
use App\Models\Remark;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RemarkController extends Controller
{
    public function store(RemarkCreateRequest $request)
    {
        if ($request->has('remarkable_type') && $request->has('remarkable_id')) {
            $remarkable = $request->remarkable_type::find($request->remarkable_id);
            $remark = Remark::create([
                'content' => $request->content,
                'user_id' => Auth::user()->id,
            ]);
            $remarkable->remarks()->attach($remark->id, [
                'internal' => $request->boolean('internal', false),
            ]);

            if ($request->wantsJson()) {
                return response()->json($remark->load('user'), 201);
            }

            return redirect()->back()->with([
                'success' => 'Opmerking is toegevoegd.',
            ]);
        }
    }

    public function destroy(Remark $remark)
    {
        $link = DB::table('remarkables')
            ->where('remark_id', $remark->id)
            ->first();

        if ($link && ltrim((string) $link->remarkable_type, '\\') === 'App\\Models\\Event') {
            $event = Event::find($link->remarkable_id);
            if (! $event || ! request()->user()->can('provideFeedback', $event)) {
                abort(403);
            }
        }

        $remark->delete();

        if (request()->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->back()->with([
            'success' => 'Opmerking is verwijderd.',
        ]);
    }
}
