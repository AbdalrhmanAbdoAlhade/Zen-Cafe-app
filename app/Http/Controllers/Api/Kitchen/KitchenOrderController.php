<?php

namespace App\Http\Controllers\Api\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KitchenOrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
    ) {
    }

    /**
     * GET /api/kitchen/orders?status=accepted,preparing
     */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->user('staff');

        $statuses = $request->filled('status')
            ? explode(',', $request->input('status'))
            : ['accepted', 'preparing'];

        $orders = Order::query()
            ->where('branch_id', $staff->branch_id)
            ->whereIn('status', $statuses)
            ->with(['items.options.optionValue', 'items.menuItem', 'table'])
            ->oldest() // المطبخ محتاج يشوف الأقدم الأول (FIFO)
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function startPreparing(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->startPreparing($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    public function markReady(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->markReady($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    private function authorizeSameBranch(Request $request, Order $order): void
    {
        if ($order->branch_id !== $request->user('staff')->branch_id) {
            abort(403, 'هذا الطلب لا يخص فرعك.');
        }
    }
}
