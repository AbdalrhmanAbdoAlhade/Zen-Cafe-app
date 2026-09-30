<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZatcaSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZatcaSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $setting = ZatcaSetting::current();

        return response()->json([
            'data' => $setting,
            'is_ready' => $setting?->isReady() ?? false,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['required', 'string', 'size:15', 'regex:/^3\d{13}3$/'],
            'cr_number' => ['nullable', 'string', 'max:20'],
            'address_ar' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $setting = ZatcaSetting::current();

        if ($setting) {
            $setting->update($data);
        } else {
            $setting = ZatcaSetting::create(array_merge([
                'is_enabled' => true,
                'last_invoice_seq' => 0,
            ], $data));
        }

        return response()->json([
            'message' => 'تم تحديث إعدادات ZATCA',
            'data' => $setting->fresh(),
            'is_ready' => $setting->fresh()->isReady(),
        ]);
    }
}