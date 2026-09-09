<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * POST /api/menu/{token}/orders
     * محمي بـ middleware EnsureMenuAccessVerified.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $qrCode = $request->attributes->get('resolved_qr_code');

        $result = $this->orderService->createOrder($qrCode, $request->validated());

        return response()->json([
            'order' => [
                'id' => $result['order']->id,
                'status' => $result['order']->status,
                'order_type' => $result['order']->order_type,
                'estimated_preparation_minutes' => (int) $result['order']->estimated_preparation_minutes,
                'estimated_ready_at' => $result['order']->estimated_ready_at,
                'total_amount' => (float) $result['order']->total_amount,
                'redeemed_points' => (int) $result['order']->redeemed_points,
                'redeemed_amount' => (float) $result['order']->redeemed_amount,
                'payable_amount' => $result['order']->payableAmount(),
                'earned_points' => (int) $result['order']->earned_points,
                'items' => $result['order']->items,
            ],
            // بترجع بس أول مرة يتعمل فيها الحساب - الفرونت يطبعها للزبون
            'customer_credentials' => $result['customer_credentials'],
        ], 201);
    }

    /**
     * GET /api/menu/{token}/orders/{order}
     * محمي بـ middleware EnsureMenuAccessVerified - متابعة أوردر تابع لنفس الـ QR بس.
     */
    public function show(Request $request, string $token, Order $order): JsonResponse
{
    $qrCode = $request->attributes->get('resolved_qr_code');

    if ($order->qr_code_id !== $qrCode->id) {
        abort(404, 'هذا الطلب غير موجود لهذا الرمز.');
    }

    return response()->json([
        'order' => [
            'id' => $order->id,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'redeemed_points' => (int) $order->redeemed_points,
            'redeemed_amount' => (float) $order->redeemed_amount,
            'payable_amount' => $order->payableAmount(),
            'earned_points' => (int) $order->earned_points,
           'items' => $order->items()
    ->with([
        'menuItem',
        'options',
    ])
    ->get(),
        ],
    ]);
}
}
