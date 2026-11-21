<?php

namespace App\Http\Controllers;

use App\Actions\Remarks\AddRemarkAction;
use App\Domain\Signals\Attachments\RemarkRemoved;
use App\Domain\Signals\Signals;
use App\Http\Requests\RemarkCreateRequest;
use App\Http\Requests\RemarkDestroyRequest;
use App\Models\Remark;
use Illuminate\Support\Facades\Auth;

class RemarkController extends Controller
{
    public function store(RemarkCreateRequest $request)
    {
        $record_class = $request->remarkableClass();
        $remarkable = $record_class::findOrFail($request->validated('remarkable_id'));
        $remark = app(AddRemarkAction::class)->execute(
            $remarkable,
            $request->validated('content'),
            $request->boolean('internal', false),
            Auth::id(),
        );

        if ($request->wantsJson()) {
            return response()->json($remark->load('user'), 201);
        }

        return redirect()->back()->with([
            'success' => 'Opmerking is toegevoegd.',
        ]);
    }

    public function destroy(RemarkDestroyRequest $request, Remark $remark)
    {
        $remarkable_record = $remark->attachedRecord();
        if ($remarkable_record) {
            Signals::dispatch(new RemarkRemoved($remarkable_record, $remark->id, $remark->content));
        }

        $remark->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->back()->with([
            'success' => 'Opmerking is verwijderd.',
        ]);
    }
}
