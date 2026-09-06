<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\VerifyLocationRequest;
use App\Http\Requests\Menu\VerifyManualCodeRequest;
use App\Models\MenuAccessLog;
use App\Services\GeofenceService;
use App\Services\MenuAccessService;
use App\Services\MenuBuilderService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuAccessController extends Controller
{
    public function __construct(
        private readonly QrTokenService $qrTokenService,
        private readonly GeofenceService $geofenceService,
        private readonly MenuAccessService $menuAccessService,
        private readonly MenuBuilderService $menuBuilderService,
    ) {
    }

    /**
     * GET /api/menu/{token}
     * بيانات الفرع الأساسية قبل أي تحقق - عشان الفرونت يعرض اسم الفرع
     * ويطلب صلاحية الموقع من المستخدم.
     */
    public function show(string $token): JsonResponse
    {
        $qrCode = $this->qrTokenService->resolve($token);
        $branch = $qrCode->branch;

        return response()->json([
            'branch' => [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
            ],
            'has_manual_access_fallback' => (bool) $qrCode->manual_access_code,
        ]);
    }

    /**
     * POST /api/menu/{token}/verify-location
     * التحقق من الجيوفنسينج - لو داخل النطاق يرجع توكين وصول مؤقت.
     */
    public function verifyLocation(VerifyLocationRequest $request, string $token): JsonResponse
    {
        $qrCode = $this->qrTokenService->resolve($token);
        $branch = $qrCode->branch;

        $distance = $this->geofenceService->distanceInMeters(
            (float) $branch->lat,
            (float) $branch->lng,
            (float) $request->input('lat'),
            (float) $request->input('lng'),
        );

        $allowed = $distance <= $qrCode->effectiveRadius();

        MenuAccessLog::create([
            'qr_code_id' => $qrCode->id,
            'ip' => $request->ip(),
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'distance_from_branch' => $distance,
            'access_method' => 'geofence',
            'allowed' => $allowed,
        ]);

        if (! $allowed) {
            return response()->json([
                'message' => 'يبدو أنك خارج نطاق الفرع. يمكنك طلب كود دخول يدوي من الجرسون.',
            ], 403);
        }

        return response()->json([
            'access_token' => $this->menuAccessService->issueToken($qrCode),
        ]);
    }

    /**
     * POST /api/menu/{token}/manual-access
     * الـ Fallback لو المستخدم رفض مشاركة الموقع - كود يدّيه الجرسون.
     */
    public function verifyManualCode(VerifyManualCodeRequest $request, string $token): JsonResponse
    {
        $qrCode = $this->qrTokenService->resolve($token);

        $allowed = $this->qrTokenService->verifyManualCode($qrCode, $request->input('code'));

        MenuAccessLog::create([
            'qr_code_id' => $qrCode->id,
            'ip' => $request->ip(),
            'access_method' => 'manual_code',
            'allowed' => $allowed,
        ]);

        if (! $allowed) {
            return response()->json([
                'message' => 'الكود غير صحيح.',
            ], 403);
        }

        return response()->json([
            'access_token' => $this->menuAccessService->issueToken($qrCode),
        ]);
    }

    /**
     * GET /api/menu/{token}/items
     * محمي بـ middleware EnsureMenuAccessVerified - المنيو الفعلي.
     */
    public function items(Request $request): JsonResponse
    {
        $qrCode = $request->attributes->get('resolved_qr_code');

        return response()->json([
            'categories' => $this->menuBuilderService->getMenuForBranch($qrCode->branch),
        ]);
    }
}
