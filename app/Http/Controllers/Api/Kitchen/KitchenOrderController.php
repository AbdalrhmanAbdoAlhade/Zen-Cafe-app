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
}