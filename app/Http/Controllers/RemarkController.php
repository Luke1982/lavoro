<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemarkCreateRequest;
use App\Models\Remark;
use Illuminate\Support\Facades\Auth;

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
            return redirect()->back()->with([
                'success' => 'Opmerking is toegevoegd.',
            ]);
        }
    }

    public function destroy(Remark $remark)
    {
        $remark->delete();
        return redirect()->back()->with([
            'success' => 'Opmerking is verwijderd.',
        ]);
    }
}
