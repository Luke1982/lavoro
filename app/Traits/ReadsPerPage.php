<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait ReadsPerPage
{
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int) $request->input('perPage', $default)));
    }
}
