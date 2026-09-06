<?php

namespace App\Http\Controllers\Api\Cashier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cashier\RecordPaymentRequest;
use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierOrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly OrderPaymentService $orderPaymentService,
    ) {
    }

    /**
     * GET /api/cashier/orders?status=pending,accepted
     */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->user('staff');

        $statuses = $request->filled('status')
            ? explode(',', $request->input('status'))
            : null;

        $orders = Order::query()
            ->where('branch_id', $staff->branch_id)
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->with(['items.options', 'table', 'customer'])
            ->latest()
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->accept($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->reject($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    public function markServed(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->markServed($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    public function payment(RecordPaymentRequest $request, Order $order): JsonResponse
    {
        $this->authorizeSameBranch($request, $order);

        $payment = $this->orderPaymentService->recordPayment(
            $order,
            $request->user('staff'),
            $request->input('method'),
            (float) $request->input('amount'),
        );

        $freshOrder = $order->fresh();

        return response()->json([
            'payment' => $payment,
            'order' => $freshOrder,
            'payable_amount' => $freshOrder->payableAmount(),
        ]);
    }

    private function authorizeSameBranch(Request $request, Order $order): void
    {
        if ($order->branch_id !== $request->user('staff')->branch_id) {
            abort(403, 'هذا الطلب لا يخص فرعك.');
        }
    }
}
