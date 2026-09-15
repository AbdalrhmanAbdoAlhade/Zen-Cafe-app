<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreCheckoutController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * POST /api/store/orders
     * الفرع هنا بيتحدد يدوي (أو أول فرع رئيسي) - المتجر مش مرتبط بموقع جغرافي زي QR.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $branch = Branch::main()->first() ?? Branch::active()->firstOrFail();

        $result = $this->orderService->createStoreOrder($branch, $request->validated());

        return response()->json([
            'order' => $this->orderService->serializeOrder($result['order']),
            'customer_auth_token' => $result['customer_auth_token'],
            'payment' => $result['payment'] ?? null,
        ], 201);
    }

    /**
     * GET /api/store/orders/{order}
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->order_type === 'store', 404);

        return response()->json([
            'order' => $this->orderService->serializeOrder(
                $order->load(['items.productVariant.product', 'shipment'])
            ),
        ]);
    }
}