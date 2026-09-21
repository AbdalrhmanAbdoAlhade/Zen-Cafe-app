<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerOrdersFilterRequest;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Order;
use App\Services\LoyaltyService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerProfileController extends Controller
{
     public function __construct(
        private readonly OrderService $orderService,
        private readonly LoyaltyService $loyaltyService,
    ) {
    }

    /* ============================================================
     |  GET /api/customer/profile
     |  بيانات العميل + نقاط الولاء + إحصائيات + آخر 5 طلبات
     ============================================================ */
    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $recentOrders = $this->baseOrdersQuery($customer)
            ->with($this->orderRelations())
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'customer' => $this->serializeCustomer($customer),
            'loyalty' => $this->loyaltySummary($customer),
            'stats' => $this->orderStats($customer),
            'recent_orders' => $recentOrders
                ->map(fn (Order $order) => $this->serializeOrderWithBranch($order))
                ->values(),
        ]);
    }

    /* ============================================================
     |  GET /api/customer/profile/orders
     |  طلبات العميل مع فلترة بالحالة/النوع/الفرع/التاريخ + Pagination
     |
     |  أمثلة:
     |   ?status=paid
     |   ?status=pending,preparing,ready
     |   ?order_type=store&shipping_status=shipped
     |   ?from=2026-01-01&to=2026-03-31&sort=highest&per_page=15
     ============================================================ */
    public function orders(CustomerOrdersFilterRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $filters = $request->validated();

        $query = $this->baseOrdersQuery($customer)
            ->with($this->orderRelations());

        $this->applyFilters($query, $filters);

        $orders = $query->paginate($filters['per_page'] ?? 10)->withQueryString();

        return response()->json([
            'data' => collect($orders->items())
                ->map(fn (Order $order) => $this->serializeOrderWithBranch($order))
                ->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
            'filters' => [
                'applied' => $filters,
                'available_statuses' => $this->statusCounts($customer),
            ],
        ]);
    }

    /* ============================================================
     |  GET /api/customer/profile/orders/{order}
     |  تفاصيل طلب واحد — بيتأكد إن الطلب بتاع العميل نفسه
     ============================================================ */
    public function orderDetails(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        abort_unless((int) $order->customer_id === (int) $customer->id, 404);

        $order->load(array_merge($this->orderRelations(), ['statusLogs', 'payment']));

        return response()->json([
            'order' => array_merge(
                $this->serializeOrderWithBranch($order),
                [
                    'notes' => $order->notes,
                    'payment' => $order->payment ? [
                        'method' => $order->payment->method ?? null,
                        'amount' => (float) ($order->payment->amount ?? 0),
                        'paid_at' => $order->payment->created_at,
                    ] : null,
                    'status_timeline' => $order->statusLogs->map(fn ($log) => [
                        'status' => $log->status,
                        'at' => $log->created_at,
                    ])->values(),
                ]
            ),
        ]);
    }

    /* ============================================================
     |  PATCH /api/customer/profile
     |  تعديل الاسم والإيميل (التليفون ثابت - هو معرّف الحساب)
     ============================================================ */
    public function update(UpdateCustomerProfileRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $customer->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث البيانات بنجاح.',
            'customer' => $this->serializeCustomer($customer->fresh()),
        ]);
    }

    /* ============================================================
     |  Helpers
     ============================================================ */

    private function baseOrdersQuery(Customer $customer): Builder
    {
        return Order::query()->where('customer_id', $customer->id);
    }

    private function orderRelations(): array
    {
        return [
            'branch:id,name_ar,name_en',
            'items.menuItem',
            'items.options.menuOptionValue',
            'items.productVariant.product',
            'shipment',
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(
                !empty($filters['status']),
                fn (Builder $q) => $q->whereIn('status', (array) $filters['status'])
            )
            ->when(
                !empty($filters['order_type']),
                fn (Builder $q) => $q->whereIn('order_type', (array) $filters['order_type'])
            )
            ->when(
                !empty($filters['branch_id']),
                fn (Builder $q) => $q->where('branch_id', $filters['branch_id'])
            )
            ->when(
                !empty($filters['shipping_status']),
                fn (Builder $q) => $q->whereHas(
                    'shipment',
                    fn ($s) => $s->where('status', $filters['shipping_status'])
                )
            )
            ->when(
                !empty($filters['from']),
                fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from'])
            )
            ->when(
                !empty($filters['to']),
                fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to'])
            );

        match ($filters['sort'] ?? 'latest') {
            'oldest' => $query->oldest(),
            'highest' => $query->orderByDesc('total_amount'),
            'lowest' => $query->orderBy('total_amount'),
            default => $query->latest(),
        };
    }

    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'member_since' => $customer->created_at,
        ];
    }

     private function loyaltySummary(Customer $customer): array
    {
        $settings = LoyaltySetting::current();
        // النقاط الصالحة فعليًا بس (مش المستعملة ولا المنتهية)
        $balance = $this->loyaltyService->availablePoints($customer);
        $value = (float) $settings->point_redemption_value;
        $minimum = (int) $settings->minimum_points_to_redeem;

        $totals = DB::table('loyalty_points_transactions')
            ->where('customer_id', $customer->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'earn' THEN points ELSE 0 END), 0) as earned,
                COALESCE(SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END), 0) as redeemed
            ")
            ->first();

        return [
            'points_balance' => $balance,
            'points_value' => round($balance * $value, 2),
            'total_points_earned' => (int) ($totals->earned ?? 0),
            'total_points_redeemed' => (int) ($totals->redeemed ?? 0),
            'minimum_points_to_redeem' => $minimum,
            'point_redemption_value' => $value,
            'points_earn_rate' => (float) $settings->points_earn_rate,
            'can_redeem_now' => $balance >= $minimum && $value > 0,
            'points_to_next_redemption' => max(0, $minimum - $balance),
            'recent_transactions' => $customer->loyaltyTransactions()
                ->limit(10)
                ->get(['id', 'order_id', 'type', 'points', 'balance_after', 'description', 'created_at']),
        ];
    }

    private function orderStats(Customer $customer): array
    {
        $row = $this->baseOrdersQuery($customer)
            ->selectRaw('
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as paid_orders,
                COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as cancelled_orders,
                COALESCE(SUM(CASE WHEN status = ? THEN (total_amount - redeemed_amount) ELSE 0 END), 0) as total_spent,
                MAX(created_at) as last_order_at
            ', [
                Order::STATUS_PAID,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
                Order::STATUS_PAID,
            ])
            ->first();

        $activeStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
            Order::STATUS_SERVED,
        ];

        return [
            'total_orders' => (int) $row->total_orders,
            'paid_orders' => (int) $row->paid_orders,
            'cancelled_orders' => (int) $row->cancelled_orders,
            'active_orders' => (int) $this->baseOrdersQuery($customer)
                ->whereIn('status', $activeStatuses)
                ->count(),
            'total_spent' => round((float) $row->total_spent, 2),
            'last_order_at' => $row->last_order_at,
        ];
    }

    /**
     * عدّاد لكل حالة — مفيد للتابات في الواجهة (الكل / جاري / مكتمل / ملغي)
     */
    private function statusCounts(Customer $customer): array
    {
        $counts = $this->baseOrdersQuery($customer)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(CustomerOrdersFilterRequest::allowedStatuses())
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    private function serializeOrderWithBranch(Order $order): array
    {
        return array_merge(
            $this->orderService->serializeOrder($order),
            [
                'branch' => $order->branch ? [
                    'id' => $order->branch->id,
                    'name_ar' => $order->branch->name_ar,
                    'name_en' => $order->branch->name_en,
                ] : null,
            ]
        );
    }
}
