<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SerialScanRequest;
use App\Services\SerialNumberOcrService;
use Illuminate\Http\JsonResponse;

class SerialOcrController extends Controller
{
    public function scan(SerialScanRequest $request, SerialNumberOcrService $ocr_service): JsonResponse
    {
        try {
            $result = $ocr_service->extractCandidates($request->file('image')->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Tekstherkenning is niet beschikbaar op de server. Neem contact op met de beheerder.',
            ], 503);
        }

        return response()->json($result);
    }
}
