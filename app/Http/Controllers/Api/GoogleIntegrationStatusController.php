<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class GoogleIntegrationStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $integration = Auth::user()->googleCalendarIntegration;
        if (!$integration) {
            return response()->json(['connected' => false]);
        }
        return response()->json([
            'connected' => true,
            'email' => $integration->google_account_email,
            'disabled' => $integration->isDisabled(),
            'backfill_total' => $integration->backfill_total,
            'backfill_done' => $integration->backfill_done,
        ]);
    }
}
