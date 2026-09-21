<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Services\MenuBuilderService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\BranchWorkingHour;

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
/**
 * GET /api/menu/{token}
 * بيانات الفرع الكاملة + مواقيت العمل
 */
public function show(string $token): JsonResponse
{
    $qrCode = $this->qrTokenService->resolve($token);
    $branch = $qrCode->branch->load('workingHours');

    return response()->json([
        'branch' => [
            'id'                     => $branch->id,
            'name_ar'                => $branch->name_ar,
            'name_en'                => $branch->name_en,
            'address_ar'             => $branch->address_ar,
            'address_en'             => $branch->address_en,
            'logo'                   => $branch->logo,
            'email'                  => $branch->email,
            'phone'                  => $branch->phone,
            'social_links'           => $branch->social_links,
            'lat'                    => (float) $branch->lat,
            'lng'                    => (float) $branch->lng,
            'default_radius_meters'  => (int) $branch->default_radius_meters,
            'is_active'              => (bool) $branch->is_active,
            'is_main'                => (bool) $branch->is_main,
            'is_online_paused'       => (bool) $branch->is_online_paused,
            'pause_reason'           => $branch->pause_reason,
            'paused_at'              => $branch->paused_at,
            'is_open_now'            => $branch->isOpenNow(),
            'working_hours'          => $branch->workingHours->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'day_name_ar' => \App\Models\BranchWorkingHour::DAYS[$hour->day_of_week] ?? null,
                'opens_at'    => $hour->opens_at,
                'closes_at'   => $hour->closes_at,
                'is_closed'   => $hour->isClosed(),
            ])->values(),
        ],
        'online_ordering_paused'      => (bool) $branch->is_online_paused,
        'pause_reason'                => $branch->pause_reason,
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
  /**
 * GET /api/menu/{token}/items
 * المنيو الفعلي + تفاصيل البرانش
 */
public function items(Request $request, string $token): JsonResponse
{
    $qrCode = $this->qrTokenService->resolve($token);
    $branch = $qrCode->branch->load('workingHours'); // ← مهم

    $result = $this->menuBuilderService->getMenuForBranch(
        branch: $branch,
        categoryId: $request->query('category_id') ? (int) $request->query('category_id') : null,
        perPage: 15,
        onlineOnly: true,
        minPrice: $request->query('min_price') !== null ? (float) $request->query('min_price') : null,
        maxPrice: $request->query('max_price') !== null ? (float) $request->query('max_price') : null,
        sort: $request->query('sort'),
    );

    return response()->json([
        'branch' => [
            'id'                     => $branch->id,
            'name_ar'                => $branch->name_ar,
            'name_en'                => $branch->name_en,
            'address_ar'             => $branch->address_ar,
            'address_en'             => $branch->address_en,
            'logo'                   => $branch->logo,
            'email'                  => $branch->email,
            'phone'                  => $branch->phone,
            'social_links'           => $branch->social_links,
            'lat'                    => (float) $branch->lat,
            'lng'                    => (float) $branch->lng,
            'default_radius_meters'  => (int) $branch->default_radius_meters,
            'is_active'              => (bool) $branch->is_active,
            'is_main'                => (bool) $branch->is_main,
            'is_online_paused'       => (bool) $branch->is_online_paused,
            'pause_reason'           => $branch->pause_reason,
            'paused_at'              => $branch->paused_at,
            'is_open_now'            => $branch->isOpenNow(), // ← مفيد للفرونت
            'working_hours'          => $branch->workingHours->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'day_name_ar' => BranchWorkingHour::DAYS[$hour->day_of_week] ?? null,
                'opens_at'    => $hour->opens_at,
                'closes_at'   => $hour->closes_at,
                'is_closed'   => $hour->isClosed(),
            ])->values(),
        ],
        'online_ordering_paused'      => (bool) $branch->is_online_paused,
        'pause_reason'                => $branch->pause_reason,
        'current_prep_offset_minutes' => (int) $branch->current_prep_offset_minutes,
        'data'                        => $result['data'],
        'meta'                        => $result['meta'],
        'links'                       => $result['links'],
    ]);
}
}