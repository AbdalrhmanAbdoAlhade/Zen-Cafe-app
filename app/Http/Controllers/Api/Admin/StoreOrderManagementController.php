<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreOrderManagementController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * GET /api/admin/store-orders?status=&shipping_status=
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('order_type', 'store')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->with(['items.productVariant.product', 'shipment', 'customer'])
            ->when(
                $request->filled('shipping_status'),
                fn ($q) => $q->whereHas('shipment', fn ($s) => $s->where('status', $request->query('shipping_status')))
            )
            ->latest()
            ->paginate(20);

        return response()->json($orders);
    }

    public function markShipped(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->order_type === 'store' && $order->fulfillment_type === 'shipping', 404);

        $data = $request->validate(['tracking_number' => ['nullable', 'string', 'max:100']]);

        $order->shipment?->markShipped($data['tracking_number'] ?? null);

        return response()->json([
            'message' => 'تم تحديث حالة الشحن',
            'data' => $this->orderService->serializeOrder($order->fresh(['shipment'])),
        ]);
    }

    public function markDelivered(Order $order): JsonResponse
    {
        abort_unless($order->order_type === 'store' && $order->fulfillment_type === 'shipping', 404);

        $order->shipment?->markDelivered();

        return response()->json([
            'message' => 'تم تأكيد التسليم',
            'data' => $this->orderService->serializeOrder($order->fresh(['shipment'])),
        ]);
    }

    /**
     * POST /api/admin/store-orders/{order}/cancel
     * إلغاء أوردر متجر (يرجّع المخزون تلقائيًا عبر transitionStore).
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->order_type === 'store', 404);

        $staff = $request->user('staff');
        $order = $this->orderStatusService->transitionStore($order, Order::STATUS_CANCELLED, $staff);

        return response()->json([
            'message' => 'تم إلغاء الأوردر وإرجاع المخزون',
            'data' => $this->orderService->serializeOrder($order),
        ]);
    }
}