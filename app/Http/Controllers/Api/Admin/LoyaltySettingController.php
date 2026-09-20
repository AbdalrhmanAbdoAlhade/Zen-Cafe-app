<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltySettingController extends Controller
{
    /**
     * GET /admin/loyalty-settings
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => LoyaltySetting::current()]);
    }

    /**
     * PUT /admin/loyalty-settings
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points_earn_rate' => ['required', 'numeric', 'min:0.01'],
            'point_redemption_value' => ['required', 'numeric', 'min:0.01'],
            'minimum_points_to_redeem' => ['required', 'integer', 'min:0'],
          // في الـ validate بتاع update
'points_expiry_months'     => ['sometimes', 'integer', 'min:0', 'max:60'],
'tier_silver_min_spent'    => ['sometimes', 'numeric', 'min:0'],
'tier_gold_min_spent'      => ['sometimes', 'numeric', 'min:0'],
        ]);

        $setting = LoyaltySetting::current();

        $data['updated_by_staff_id'] = $request->user('staff')?->id;

        $setting->update($data);

        return response()->json([
            'message' => 'تم تحديث إعدادات نقاط الولاء',
            'data' => $setting->fresh(),
        ]);
    }
}