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
     * GET /api/kitchen/orders?status=accepted,preparing&branch_id=1
     *
     * - الموظف: أوردرات فرعه بس
     * - الأدمن: كل الفروع مع فلترة اختيارية
     */
    public function index(Request $request): JsonResponse
    {
        [$actor, $isAdmin] = $this->resolveActor($request);

        $statuses = $request->filled('status')
            ? explode(',', $request->input('status'))
            : ['accepted', 'preparing'];

        $query = Order::query()
            ->whereIn('status', $statuses)
            ->with(['items.options.optionValue', 'items.menuItem', 'table', 'branch']);

        if ($isAdmin) {
            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->input('branch_id'));
            }
        } else {
            $query->where('branch_id', $actor->branch_id);
        }

        // المطبخ FIFO — الأقدم الأول
        $orders = $query->oldest()->get();

        return response()->json(['orders' => $orders]);
    }

    public function startPreparing(Request $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->startPreparing($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    public function markReady(Request $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->markReady($order, $request->user('staff'));

        return response()->json(['order' => $order]);
    }

    /* ============================================================
     |  Helpers
     ============================================================ */

    private function resolveActor(Request $request): array
    {
        $staff = $request->user('staff');
        if ($staff) {
            return [$staff, false];
        }

        $admin = $request->user('sanctum');
        if ($admin) {
            return [$admin, true];
        }

        abort(401, 'غير مصرح.');
    }

    private function denyAdmin(Request $request): ?JsonResponse
    {
        if ($request->attributes->get('actor_type') === 'admin') {
            return response()->json([
                'message' => 'الأدمن للقراءة فقط في هذه الشاشة.',
            ], 403);
        }

        return null;
    }

    private function authorizeSameBranch(Request $request, Order $order): void
    {
        $staff = $request->user('staff');

        if (! $staff) {
            return;
        }

        if ((int) $order->branch_id !== (int) $staff->branch_id) {
            abort(403, 'هذا الطلب لا يخص فرعك.');
        }
    }
  
  	/**
 * POST /api/cashier/orders/{order}/paid
 * تأكيد دفع أوردر متجر (لو مش مدفوع أونلاين)
 */
public function markStorePaid(Request $request, Order $order): JsonResponse
{
    if ($deny = $this->denyAdmin($request)) {
        return $deny;
    }

    $this->authorizeSameBranch($request, $order);
    $this->ensureStoreOrder($order);

    if ($order->status !== Order::STATUS_PENDING) {
        return response()->json([
            'message' => 'لا يمكن تأكيد الدفع إلا من حالة pending',
        ], 422);
    }

    $order->update(['status' => Order::STATUS_PAID]);

    // لو عندك OrderStatusLog
    \App\Models\OrderStatusLog::create([
        'order_id' => $order->id,
        'status' => Order::STATUS_PAID,
        'changed_by_staff_id' => $request->user('staff')?->id,
    ]);

    return response()->json([
        'message' => 'تم تأكيد الدفع',
        'order' => $this->formatOrder($order->fresh()),
    ]);
}

/**
 * POST /api/cashier/orders/{order}/shipped
 * Body اختياري: { "tracking_number": "123ABC" }
 */
public function markShipped(Request $request, Order $order): JsonResponse
{
    if ($deny = $this->denyAdmin($request)) {
        return $deny;
    }

    $this->authorizeSameBranch($request, $order);
    $this->ensureStoreOrder($order);

    if ($order->status !== Order::STATUS_PAID) {
        return response()->json([
            'message' => 'لازم يكون الأوردر مدفوع قبل الشحن',
        ], 422);
    }

    $shipment = $order->shipment;

    if (! $shipment) {
        return response()->json([
            'message' => 'مفيش بيانات شحن لهذا الطلب',
        ], 422);
    }

    if ($shipment->status === \App\Models\OrderShipment::STATUS_SHIPPED) {
        return response()->json([
            'message' => 'الأوردر مشحون بالفعل',
        ], 422);
    }

    $tracking = $request->input('tracking_number');
    $shipment->markShipped($tracking);

    return response()->json([
        'message' => 'تم تعليم الطلب كـ مشحون',
        'order' => $this->formatOrder($order->fresh(['shipment'])),
        'shipment' => $shipment->fresh(),
    ]);
}

/**
 * POST /api/cashier/orders/{order}/delivered
 */
public function markDelivered(Request $request, Order $order): JsonResponse
{
    if ($deny = $this->denyAdmin($request)) {
        return $deny;
    }

    $this->authorizeSameBranch($request, $order);
    $this->ensureStoreOrder($order);

    $shipment = $order->shipment;

    if (! $shipment) {
        return response()->json([
            'message' => 'مفيش بيانات شحن لهذا الطلب',
        ], 422);
    }

    if ($shipment->status !== \App\Models\OrderShipment::STATUS_SHIPPED) {
        return response()->json([
            'message' => 'لازم يكون مشحون أولاً قبل التسليم',
        ], 422);
    }

    $shipment->markDelivered();

    return response()->json([
        'message' => 'تم تعليم الطلب كـ تم التسليم',
        'order' => $this->formatOrder($order->fresh(['shipment'])),
        'shipment' => $shipment->fresh(),
    ]);
}

/**
 * POST /api/cashier/orders/{order}/cancel
 */
public function cancelStoreOrder(Request $request, Order $order): JsonResponse
{
    if ($deny = $this->denyAdmin($request)) {
        return $deny;
    }

    $this->authorizeSameBranch($request, $order);
    $this->ensureStoreOrder($order);

    if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PAID], true)) {
        return response()->json([
            'message' => 'لا يمكن إلغاء الطلب في حالته الحالية',
        ], 422);
    }

    // لو مدفوع ومحتاج ترجع فلوس → STATUS_REFUNDED بدل cancelled
    // هنا هنعمل cancel بسيط
    $order->update(['status' => Order::STATUS_CANCELLED]);

    \App\Models\OrderStatusLog::create([
        'order_id' => $order->id,
        'status' => Order::STATUS_CANCELLED,
        'changed_by_staff_id' => $request->user('staff')?->id,
    ]);

    return response()->json([
        'message' => 'تم إلغاء الطلب',
        'order' => $this->formatOrder($order->fresh()),
    ]);
}

/**
 * تأكد إن الأوردر من نوع store
 */
private function ensureStoreOrder(Order $order): void
{
    if ($order->order_type !== 'store') {
        abort(422, 'هذا الإجراء متاح فقط لأوردرات المتجر');
    }
}
}