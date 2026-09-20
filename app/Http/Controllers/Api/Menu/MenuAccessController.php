<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Services\MenuBuilderService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuAccessController extends Controller
{
    public function __construct(
        private readonly QrTokenService $qrTokenService,
        private readonly MenuBuilderService $menuBuilderService,
    ) {
    }

    /**
     * GET /api/menu/{token}
     * بيانات الفرع الأساسية - الفرونت يعرضها لو حابب (اسم الفرع مثلاً).
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
                 'email' => $branch->email,
                'phone' => $branch->phone,
            ],
            // وقت الذروة الحالي - يتضاف على وقت تجهيز أي طلب جديد، بيظهر للعميل قبل ما يطلب
            'current_prep_offset_minutes' => (int) $branch->current_prep_offset_minutes,
        ]);
    }

    /**
     * GET /api/menu/{token}/items
     * المنيو الفعلي - الحماية الوحيدة إن التوكين نفسه يتفك لفرع صحيح.
     *
     * Query params:
     * - category_id (optional)
     * - page (optional)
     */
   public function items(Request $request, string $token): JsonResponse
{
    $qrCode = $this->qrTokenService->resolve($token);

    $result = $this->menuBuilderService->getMenuForBranch(
        branch: $qrCode->branch,
        categoryId: $request->query('category_id') ? (int) $request->query('category_id') : null,
        perPage: 15,
        minPrice: $request->query('min_price') !== null ? (float) $request->query('min_price') : null,
        maxPrice: $request->query('max_price') !== null ? (float) $request->query('max_price') : null,
        sort: $request->query('sort'),
    );

    return response()->json($result);
}
}