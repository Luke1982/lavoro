<?php

namespace App\Http\Controllers;

use App\Http\Requests\DialShowRequest;
use Inertia\Response;

/**
 * Where the Bellen button of a reminder lands in a browser. A browser does not
 * reliably hand a tel: address from a notification to the phone's dialer, so the
 * button opens this page with one large button that does, under a real tap.
 */
class DialController extends Controller
{
    public function __invoke(DialShowRequest $request): Response
    {
        return inertia('Dial/ShowPage', [
            'number' => $request->validated('number'),
        ]);
    }
}
