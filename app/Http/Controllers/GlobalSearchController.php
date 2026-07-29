<?php

namespace App\Http\Controllers;

use App\Domain\Search\GlobalSearch;
use App\Http\Requests\GlobalSearchRequest;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        $term = trim($request->validated()['q']);

        return response()->json([
            'groups' => (new GlobalSearch)->run($request->user(), $term),
        ]);
    }
}
