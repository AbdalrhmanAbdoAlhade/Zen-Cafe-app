<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCouponException;
use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponValidationController extends Controller
{
    public function __construct(
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * POST /api/coupons/validate
     */
    public function validate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                => ['required', 'string', 'max:50'],
            'subtotal'            => ['required', 'numeric', 'min:0'],
            'branch_id'           => ['nullable', 'integer', 'exists:branches,id'],
            'channel'             => ['nullable', 'string', 'in:qr_menu,online_menu,store'],
            'shipping_fee'        => ['nullable', 'numeric', 'min:0'],
            'items'               => ['nullable', 'array'],
            'items.*.id'          => ['required_with:items', 'integer'],
            'items.*.type'        => ['required_with:items', 'in:menu_item,product'],
            'items.*.qty'         => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required_with:items', 'numeric', 'min:0'],
            'items.*.category_id' => ['nullable', 'integer'],
        ]);

        $customer = $request->user('customer');

        try {
            $result = $this->couponService->validate(
                code: $data['code'],
                subtotal: (float) $data['subtotal'],
                items: $data['items'] ?? [],
                branchId: $data['branch_id'] ?? null,
                customer: $customer,
                channel: $data['channel'] ?? 'qr_menu',
                shippingFee: (float) ($data['shipping_fee'] ?? 0),
            );

            return response()->json([
                'valid'           => true,
                'code'            => $result['coupon']->code,
                'name_ar'         => $result['coupon']->name_ar,
                'type'            => $result['coupon']->type,
                'discount_amount' => $result['discount_amount'],
                'free_shipping'   => $result['free_shipping'],
                'message'         => $result['message'],
            ]);
        } catch (InvalidCouponException $e) {
            return response()->json([
                'valid'   => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}