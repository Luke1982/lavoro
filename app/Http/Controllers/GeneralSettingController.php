<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeneralSettingUpdateRequest;
use App\Models\GeneralSetting;

class GeneralSettingController extends Controller
{
    public function update(GeneralSettingUpdateRequest $request, string $key): \Illuminate\Http\JsonResponse
    {
        GeneralSetting::set($key, $request->validated()['value']);

        return response()->json(['key' => $key, 'value' => GeneralSetting::get($key)]);
    }
}
