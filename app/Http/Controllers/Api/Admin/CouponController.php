<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query()->withCount('redemptions')->latest();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        $coupons = $query->paginate($request->integer('per_page', 20));

        return response()->json($coupons);
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();

        $coupon = Coupon::create(collect($data)->except([
            'branch_ids', 'product_ids', 'menu_item_ids', 'product_category_ids',
        ])->toArray());

        $this->syncRelations($coupon, $data);

        return response()->json([
            'message' => 'تم إنشاء الكوبون بنجاح.',
            'coupon'  => $coupon->load(['branches', 'products', 'menuItems', 'productCategories']),
        ], 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        $coupon->load(['branches', 'products', 'menuItems', 'productCategories'])
               ->loadCount('redemptions');

        return response()->json(['coupon' => $coupon]);
    }

    public function update(StoreCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validated();

        $coupon->update(collect($data)->except([
            'branch_ids', 'product_ids', 'menu_item_ids', 'product_category_ids',
        ])->toArray());

        $this->syncRelations($coupon, $data);

        return response()->json([
            'message' => 'تم تحديث الكوبون بنجاح.',
            'coupon'  => $coupon->fresh()->load(['branches', 'products', 'menuItems', 'productCategories']),
        ]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json(['message' => 'تم حذف الكوبون.']);
    }

    public function toggle(Coupon $coupon): JsonResponse
    {
        $coupon->update(['is_active' => !$coupon->is_active]);

        return response()->json([
            'message'   => $coupon->is_active ? 'تم تفعيل الكوبون.' : 'تم إيقاف الكوبون.',
            'is_active' => $coupon->is_active,
        ]);
    }

    public function redemptions(Request $request, Coupon $coupon): JsonResponse
    {
        $redemptions = $coupon->redemptions()
            ->with(['order:id,total_amount,status,created_at', 'customer:id,name,phone'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($redemptions);
    }

    protected function syncRelations(Coupon $coupon, array $data): void
    {
        if (array_key_exists('branch_ids', $data)) {
            $coupon->branches()->sync($data['branch_ids'] ?? []);
        }
        if (array_key_exists('product_ids', $data)) {
            $coupon->products()->sync($data['product_ids'] ?? []);
        }
        if (array_key_exists('menu_item_ids', $data)) {
            $coupon->menuItems()->sync($data['menu_item_ids'] ?? []);
        }
        if (array_key_exists('product_category_ids', $data)) {
            $coupon->productCategories()->sync($data['product_category_ids'] ?? []);
        }
    }
}