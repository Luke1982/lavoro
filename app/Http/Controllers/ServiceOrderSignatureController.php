<?php

namespace App\Http\Controllers;

use App\Domain\Signals\ServiceOrders\ServiceOrderSignatureRemoved;
use App\Domain\Signals\Signals;
use App\Http\Requests\ServiceOrderSignatureDestroyRequest;
use App\Models\ServiceOrder;

class ServiceOrderSignatureController extends Controller
{
    public function destroy(ServiceOrderSignatureDestroyRequest $request, ServiceOrder $serviceorder)
    {
        $previous_signed_by = $serviceorder->signed_by;

        $serviceorder->update([
            'signed_by' => null,
            'signature_base64' => null,
        ]);

        Signals::dispatch(new ServiceOrderSignatureRemoved($serviceorder, $previous_signed_by));

        return redirect()->back()->with('success', 'Handtekening gewist');
    }
}
