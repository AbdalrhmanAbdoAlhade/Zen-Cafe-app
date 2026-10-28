<?php

namespace App\Http\Controllers\Api\Cashier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cashier\RecordPaymentRequest;
use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierOrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderService $orderService,
    ) {
    }
/**
 * POST /api/cashier/orders/{order}/settle-and-pay
 * قفل سريع + دفع من أي حالة (ضغط الشيفت).
 * Body: { "method": "cash|card", "amount": 50 }
 */
public function settleAndPay(RecordPaymentRequest $request, Order $order): JsonResponse
{
    if ($deny = $this->denyAdmin($request)) {
        return $deny;
    }

    $this->authorizeSameBranch($request, $order);

    $payment = $this->orderPaymentService->settleAndPay(
        $order,
        $request->user('staff'),
        $request->input('method'),
        (float) $request->input('amount'),
    );

    $freshOrder = $order->fresh([
        'items.menuItem',
        'items.options.menuOptionValue',
        'table',
        'customer',
        'branch',
    ]);

    return response()->json([
        'message' => 'تم قفل الطلب وتسجيل الدفع',
        'payment' => $payment,
        'order' => $this->formatOrder($freshOrder),
        'payable_amount' => $freshOrder->payableAmount(),
    ]);
}
    /**
     * GET /api/cashier/orders?status=pending,accepted&branch_id=1
     *
     * - الموظف: يشوف أوردرات فرعه بس
     * - الأدمن: يشوف كل الفروع مع فلترة اختيارية
     */
    public function index(Request $request): JsonResponse
    {
        [$actor, $isAdmin] = $this->resolveActor($request);

        $statuses = $request->filled('status')
            ? explode(',', $request->input('status'))
            : null;

        $query = Order::query()
            ->with(['items.menuItem', 'items.options.menuOptionValue', 'table', 'customer', 'branch']);

        if ($isAdmin) {
            // الأدمن: كل الفروع + فلترة اختيارية بالفرع
            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->input('branch_id'));
            }
        } else {
            // الموظف: فرعه بس
            $query->where('branch_id', $actor->branch_id);
        }

        if ($statuses) {
            $query->whereIn('status', $statuses);
        } elseif (! $isAdmin) {
            // الموظف: بس الحالات النشطة افتراضيًا
            $query->whereIn('status', ['pending', 'accepted', 'preparing', 'ready', 'served']);
        }

        $orders = $query->latest()->get();

        return response()->json([
            'orders' => $orders->map(fn ($order) => $this->formatOrder($order)),
        ]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->accept($order, $request->user('staff'));

        return response()->json(['order' => $this->formatOrder($order)]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->reject($order, $request->user('staff'));

        return response()->json(['order' => $this->formatOrder($order)]);
    }

    public function markServed(Request $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $order = $this->orderStatusService->markServed($order, $request->user('staff'));

        return response()->json(['order' => $this->formatOrder($order)]);
    }

    public function payment(RecordPaymentRequest $request, Order $order): JsonResponse
    {
        if ($deny = $this->denyAdmin($request)) {
            return $deny;
        }

        $this->authorizeSameBranch($request, $order);

        $payment = $this->orderPaymentService->recordPayment(
            $order,
            $request->user('staff'),
            $request->input('method'),
            (float) $request->input('amount'),
        );

        $freshOrder = $order->fresh(['items.menuItem', 'items.options.menuOptionValue', 'table', 'customer', 'branch']);

        return response()->json([
            'payment' => $payment,
            'order' => $this->formatOrder($freshOrder),
            'payable_amount' => $freshOrder->payableAmount(),
        ]);
    }

    /* ============================================================
     |  Helpers
     ============================================================ */

    /**
     * يرجع [actor, isAdmin]
     */
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

    /**
     * يمنع الأدمن من تنفيذ عمليات التعديل
     */
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

        // الأدمن مسموح له نظريًا (بس denyAdmin بيمنعه قبل ما يوصل هنا)
        if (! $staff) {
            return;
        }

        if ((int) $order->branch_id !== (int) $staff->branch_id) {
            abort(403, 'هذا الطلب لا يخص فرعك.');
        }
    }

    private function formatOrder(Order $order): array
    {
        $order->loadMissing(['items.menuItem', 'items.options.menuOptionValue', 'table', 'customer', 'branch']);

        $data = $this->orderService->serializeOrder($order);

        $data['table'] = $order->table ? [
            'id' => $order->table->id,
            'table_number' => $order->table->table_number,
        ] : null;

        $data['customer'] = $order->customer ? [
            'id' => $order->customer->id,
            'name' => $order->customer->name,
            'phone' => $order->customer->phone,
        ] : null;

        // لو محبّ تظهر الفرع للأدمن
        if ($order->relationLoaded('branch') && $order->branch) {
            $data['branch'] = [
                'id' => $order->branch->id,
                'name_ar' => $order->branch->name_ar,
                'name_en' => $order->branch->name_en,
            ];
        }

        return $data;
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