<?php

namespace App\Http\Controllers;

use App\Actions\Tasks\TickTaskCheckAction;
use App\Http\Requests\ServiceOrderTaskInstanceCheckUpdateRequest;
use App\Models\ServiceOrderTaskInstanceCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * Ticking a check off on site, one at a time and without reloading the werkbon,
 * so the open task box stays exactly where the mechanic left it.
 */
class ServiceOrderTaskInstanceCheckApiController extends Controller
{
    public function update(
        ServiceOrderTaskInstanceCheckUpdateRequest $request,
        ServiceOrderTaskInstanceCheck $serviceordertaskinstancecheck,
        TickTaskCheckAction $tick,
    ): JsonResponse {
        $check = $tick->execute($serviceordertaskinstancecheck, $request->boolean('is_done'));

        return response()->json(Arr::only(
            $check->load('checkedBy:id,name')->toArray(),
            ['id', 'is_done', 'checked_at', 'checked_by'],
        ));
    }
}
