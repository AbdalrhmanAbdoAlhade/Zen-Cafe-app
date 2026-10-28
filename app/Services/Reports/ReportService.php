<?php

namespace App\Services\Reports;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\OnlinePayment;
use App\Models\OrderStatusLog;
use App\Models\Customer;
use App\Models\LoyaltyPointsTransaction;
use App\Models\MenuAccessLog;
use App\Models\ProductVariant;
use App\Models\ProductStockMovement;
use App\Models\Staff;
use App\Models\User;
use App\Models\OrderPayment;
use App\Models\Cart;
use InvalidArgumentException;

class ReportService
{
  			
  		
  	    /**
     * تقرير السلال المتروكة والاستعادة.
     */
    public function abandonedCartsReport(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $base = Cart::query()
            ->whereBetween('created_at', [$range['from'], $range['to']]);

        if (! empty($filters['branch_id'])) {
            $base->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['cart_type'])) {
            $base->where('cart_type', $filters['cart_type']);
        }

        $totalsByStatus = (clone $base)
            ->selectRaw('
                status,
                COUNT(*) as count,
                COALESCE(SUM(estimated_total), 0) as value,
                COALESCE(SUM(items_count), 0) as items
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statusRow = function (string $status) use ($totalsByStatus) {
            $row = $totalsByStatus->get($status);

            return [
                'count' => $row ? (int) $row->count : 0,
                'value' => $row ? round((float) $row->value, 2) : 0.0,
                'items' => $row ? (int) $row->items : 0,
            ];
        };

        $abandoned = $statusRow(Cart::STATUS_ABANDONED);
        $recoveredRaw = Cart::query()
            ->whereNotNull('recovered_at')
            ->whereBetween('recovered_at', [$range['from'], $range['to']])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['cart_type']), fn ($q) => $q->where('cart_type', $filters['cart_type']))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(estimated_total), 0) as value, COALESCE(SUM(items_count), 0) as items')
            ->first();
        $recovered = [
            'count' => (int) ($recoveredRaw->count ?? 0),
            'value' => round((float) ($recoveredRaw->value ?? 0), 2),
            'items' => (int) ($recoveredRaw->items ?? 0),
        ];
        $converted = $statusRow(Cart::STATUS_CONVERTED);
        $active = $statusRow(Cart::STATUS_ACTIVE);

        $recoveryOfAbandonedPool = ($abandoned['count'] + $recovered['count']) > 0
            ? round(($recovered['count'] / ($abandoned['count'] + $recovered['count'])) * 100, 2)
            : 0.0;

        $byStage = Cart::query()
            ->where('status', Cart::STATUS_ABANDONED)
            ->whereBetween('abandoned_at', [$range['from'], $range['to']])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['cart_type']), fn ($q) => $q->where('cart_type', $filters['cart_type']))
            ->selectRaw('
                COALESCE(abandon_stage, "unknown") as abandon_stage,
                COUNT(*) as count,
                COALESCE(SUM(estimated_total), 0) as value
            ')
            ->groupBy('abandon_stage')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'stage' => $row->abandon_stage,
                'count' => (int) $row->count,
                'value' => round((float) $row->value, 2),
            ])
            ->values()
            ->all();

        $abandonedCartIds = Cart::query()
            ->where('status', Cart::STATUS_ABANDONED)
            ->whereBetween('abandoned_at', [$range['from'], $range['to']])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['cart_type']), fn ($q) => $q->where('cart_type', $filters['cart_type']))
            ->pluck('id');

        $topProducts = [];
        if ($abandonedCartIds->isNotEmpty()) {
            $topProducts = DB::table('cart_items')
                ->leftJoin('menu_items', 'menu_items.id', '=', 'cart_items.menu_item_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'cart_items.product_variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('cart_items.cart_id', $abandonedCartIds)
                ->selectRaw('
                    cart_items.menu_item_id,
                    cart_items.product_variant_id,
                    COALESCE(menu_items.name_ar, products.name_ar, "unknown") as name,
                    SUM(cart_items.quantity) as quantity,
                    COALESCE(SUM(cart_items.unit_price * cart_items.quantity), 0) as value
                ')
                ->groupBy(
                    'cart_items.menu_item_id',
                    'cart_items.product_variant_id',
                    'menu_items.name_ar',
                    'products.name_ar'
                )
                ->orderByDesc('quantity')
                ->limit(30)
                ->get()
                ->map(fn ($row) => [
                    'menu_item_id' => $row->menu_item_id,
                    'product_variant_id' => $row->product_variant_id,
                    'name' => $row->name,
                    'quantity' => (int) $row->quantity,
                    'value' => round((float) $row->value, 2),
                ])
                ->all();
        }

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $series = Cart::query()
                ->where('status', Cart::STATUS_ABANDONED)
                ->whereBetween('abandoned_at', [$range['from'], $range['to']])
                ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
                ->when(! empty($filters['cart_type']), fn ($q) => $q->where('cart_type', $filters['cart_type']))
                ->selectRaw('
                    DATE(abandoned_at) as date,
                    COUNT(*) as count,
                    COALESCE(SUM(estimated_total), 0) as value
                ')
                ->groupBy(DB::raw('DATE(abandoned_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'count' => (int) $row->count,
                    'value' => round((float) $row->value, 2),
                ])
                ->values()
                ->all();
        }

        $recoveredSales = Cart::query()
            ->where('status', Cart::STATUS_CONVERTED)
            ->whereNotNull('recovered_at')
            ->whereBetween('recovered_at', [$range['from'], $range['to']])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(estimated_total), 0) as value')
            ->first();

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'cart_type' => $filters['cart_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'active' => $active,
                'abandoned' => $abandoned,
                'recovered' => $recovered,
                'converted' => $converted,
                'recovery_rate_pct' => $recoveryOfAbandonedPool,
                'recovered_sales' => [
                    'count' => (int) ($recoveredSales->count ?? 0),
                    'value' => round((float) ($recoveredSales->value ?? 0), 2),
                ],
            ],
            'by_abandon_stage' => $byStage,
            'top_products_in_abandoned_carts' => $topProducts,
            'series' => $series,
            'notes' => [
                'mark_command' => 'php artisan carts:mark-abandoned --minutes=60',
                'requirement' => 'لازم الفرونت يحفظ السلة عبر API عشان التقرير يبقى له معنى',
            ],
        ];
    }
  		    /**
     * نشاط المستخدمين (Staff + Admin) والصلاحيات.
     */
    public function userActivityReport(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $staffQuery = Staff::query()->with('branch:id,name_ar,name_en');

        if (! empty($filters['branch_id'])) {
            $staffQuery->where('branch_id', $filters['branch_id']);
        }

        $staff = $staffQuery->get();

        $statusCounts = OrderStatusLog::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereNotNull('changed_by_staff_id')
            ->selectRaw('changed_by_staff_id, status, COUNT(*) as count')
            ->groupBy('changed_by_staff_id', 'status')
            ->get()
            ->groupBy('changed_by_staff_id');

        $statusTotals = OrderStatusLog::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereNotNull('changed_by_staff_id')
            ->selectRaw('changed_by_staff_id, COUNT(*) as total')
            ->groupBy('changed_by_staff_id')
            ->pluck('total', 'changed_by_staff_id');

        $paymentAgg = OrderPayment::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereNotNull('staff_id')
            ->selectRaw('staff_id, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as payments_amount')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $staffIds = $staff->pluck('id');
        $lastTokenUse = collect();
        if ($staffIds->isNotEmpty()) {
            $lastTokenUse = DB::table('personal_access_tokens')
                ->where('tokenable_type', Staff::class)
                ->whereIn('tokenable_id', $staffIds)
                ->selectRaw('tokenable_id, MAX(last_used_at) as last_used_at, MAX(created_at) as last_token_created_at')
                ->groupBy('tokenable_id')
                ->get()
                ->keyBy('tokenable_id');
        }

        $staffRows = [];
        $byRole = [];

        foreach ($staff as $s) {
            $sid = $s->id;
            $actionsTotal = (int) ($statusTotals[$sid] ?? 0);
            $statusBreakdown = [];
            foreach ($statusCounts->get($sid, collect()) as $row) {
                $statusBreakdown[$row->status] = (int) $row->count;
            }
            $pay = $paymentAgg->get($sid);
            $token = $lastTokenUse->get($sid);

            $row = [
                'staff_id' => $sid,
                'name' => $s->name,
                'username' => $s->username,
                'role' => $s->role,
                'is_active' => (bool) $s->is_active,
                'branch_id' => $s->branch_id,
                'branch_name' => $s->branch->name_ar ?? $s->branch->name_en ?? null,
                'status_actions_count' => $actionsTotal,
                'status_breakdown' => $statusBreakdown,
                'payments_count' => $pay ? (int) $pay->payments_count : 0,
                'payments_amount' => $pay ? round((float) $pay->payments_amount, 2) : 0.0,
                'last_token_used_at' => $token->last_used_at ?? null,
                'last_token_created_at' => $token->last_token_created_at ?? null,
            ];
            $staffRows[] = $row;

            $role = $s->role ?? 'unknown';
            if (! isset($byRole[$role])) {
                $byRole[$role] = [
                    'role' => $role,
                    'staff_count' => 0,
                    'active_count' => 0,
                    'status_actions_count' => 0,
                    'payments_count' => 0,
                    'payments_amount' => 0.0,
                ];
            }
            $byRole[$role]['staff_count']++;
            if ($s->is_active) {
                $byRole[$role]['active_count']++;
            }
            $byRole[$role]['status_actions_count'] += $actionsTotal;
            $byRole[$role]['payments_count'] += $row['payments_count'];
            $byRole[$role]['payments_amount'] += $row['payments_amount'];
        }

        usort($staffRows, fn ($a, $b) => $b['status_actions_count'] <=> $a['status_actions_count']);

        $roleList = array_values(array_map(function ($r) {
            $r['payments_amount'] = round($r['payments_amount'], 2);
            return $r;
        }, $byRole));

        $admins = User::query()->get(['id', 'name', 'email', 'role', 'created_at']);
        $adminIds = $admins->pluck('id');
        $adminTokens = collect();
        if ($adminIds->isNotEmpty()) {
            $adminTokens = DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $adminIds)
                ->selectRaw('tokenable_id, MAX(last_used_at) as last_used_at, MAX(created_at) as last_token_created_at, COUNT(*) as tokens_count')
                ->groupBy('tokenable_id')
                ->get()
                ->keyBy('tokenable_id');
        }

        $adminRows = $admins->map(function ($u) use ($adminTokens) {
            $t = $adminTokens->get($u->id);
            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'created_at' => optional($u->created_at)->toDateTimeString(),
                'tokens_count' => $t ? (int) $t->tokens_count : 0,
                'last_token_used_at' => $t->last_used_at ?? null,
                'last_token_created_at' => $t->last_token_created_at ?? null,
            ];
        })->values()->all();

        $topActions = OrderStatusLog::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereNotNull('changed_by_staff_id')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->all();

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
            ],
            'totals' => [
                'staff_count' => $staff->count(),
                'staff_active' => $staff->where('is_active', true)->count(),
                'status_actions_in_period' => (int) $statusTotals->sum(),
                'payments_in_period' => (int) $paymentAgg->sum('payments_count'),
                'payments_amount_in_period' => round((float) $paymentAgg->sum('payments_amount'), 2),
                'admins_count' => $admins->count(),
            ],
            'by_role' => $roleList,
            'staff_activity' => $staffRows,
            'admins' => $adminRows,
            'top_status_actions' => $topActions,
            'notes' => [
                'scope' => 'لا يوجد audit log كامل — النشاط مبني على status_logs + payments + tokens',
                'permissions' => 'الصلاحيات من حقل role على staff و users',
            ],
        ];
    }
  		    /**
     * تقرير المخزون: الحالة الحالية، المنخفض، والحركات في الفترة.
     */
    public function inventoryReport(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $variants = ProductVariant::query()
            ->with(['product:id,name_ar,name_en,sku,category_id'])
            ->get();

        $inStock = 0;
        $lowStock = 0;
        $outOfStock = 0;
        $totalUnits = 0;
        $lowStockItems = [];
        $outOfStockItems = [];

        foreach ($variants as $v) {
            $qty = (int) $v->stock_quantity;
            $threshold = (int) ($v->low_stock_threshold ?? 0);
            $totalUnits += $qty;

            $item = [
                'variant_id' => $v->id,
                'product_id' => $v->product_id,
                'product_name_ar' => $v->product->name_ar ?? null,
                'product_name_en' => $v->product->name_en ?? null,
                'sku' => $v->sku ?? $v->product->sku ?? null,
                'attributes' => $v->attributes,
                'stock_quantity' => $qty,
                'low_stock_threshold' => $threshold,
                'is_available' => (bool) $v->is_available,
            ];

            if ($qty <= 0) {
                $outOfStock++;
                $outOfStockItems[] = $item;
            } elseif ($threshold > 0 && $qty <= $threshold) {
                $lowStock++;
                $lowStockItems[] = $item;
            } else {
                $inStock++;
            }
        }

        usort($lowStockItems, fn ($a, $b) => $a['stock_quantity'] <=> $b['stock_quantity']);
        usort($outOfStockItems, fn ($a, $b) => ($a['product_name_ar'] ?? '') <=> ($b['product_name_ar'] ?? ''));

        $movementsQuery = ProductStockMovement::query()
            ->whereBetween('created_at', [$range['from'], $range['to']]);

        $movementTotals = (clone $movementsQuery)
            ->selectRaw('
                COUNT(*) as movements_count,
                COALESCE(SUM(CASE WHEN type = ? THEN ABS(quantity) ELSE 0 END), 0) as sold_units,
                COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as restocked_units,
                COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as refunded_units,
                COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as adjustment_units
            ', [
                ProductStockMovement::TYPE_SALE,
                ProductStockMovement::TYPE_RESTOCK,
                ProductStockMovement::TYPE_CANCELLATION_REFUND,
                ProductStockMovement::TYPE_ADJUSTMENT,
            ])
            ->first();

        $byType = (clone $movementsQuery)
            ->selectRaw('
                type,
                COUNT(*) as count,
                COALESCE(SUM(quantity), 0) as quantity_sum,
                COALESCE(SUM(ABS(quantity)), 0) as quantity_abs
            ')
            ->groupBy('type')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'count' => (int) $row->count,
                'quantity_sum' => (int) $row->quantity_sum,
                'quantity_abs' => (int) $row->quantity_abs,
            ])
            ->values()
            ->all();

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $series = (clone $movementsQuery)
                ->selectRaw('
                    DATE(created_at) as date,
                    COALESCE(SUM(CASE WHEN type = ? THEN ABS(quantity) ELSE 0 END), 0) as sold_units,
                    COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as restocked_units,
                    COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as refunded_units
                ', [
                    ProductStockMovement::TYPE_SALE,
                    ProductStockMovement::TYPE_RESTOCK,
                    ProductStockMovement::TYPE_CANCELLATION_REFUND,
                ])
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'sold_units' => (int) $row->sold_units,
                    'restocked_units' => (int) $row->restocked_units,
                    'refunded_units' => (int) $row->refunded_units,
                ])
                ->values()
                ->all();
        }

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'group_by' => $filters['group_by'] ?? null,
            ],
            'snapshot' => [
                'variants_count' => $variants->count(),
                'total_units' => $totalUnits,
                'in_stock' => $inStock,
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
            ],
            'low_stock_items' => array_slice($lowStockItems, 0, 50),
            'out_of_stock_items' => array_slice($outOfStockItems, 0, 50),
            'movements' => [
                'movements_count' => (int) ($movementTotals->movements_count ?? 0),
                'sold_units' => (int) ($movementTotals->sold_units ?? 0),
                'restocked_units' => (int) ($movementTotals->restocked_units ?? 0),
                'refunded_units' => (int) ($movementTotals->refunded_units ?? 0),
                'adjustment_units' => (int) ($movementTotals->adjustment_units ?? 0),
                'by_type' => $byType,
            ],
            'series' => $series,
            'notes' => [
                'reserved' => 'مفيش حقل محجوز منفصل — الخصم يتم عند إنشاء طلب المتجر',
                'scope' => 'product_variants فقط (متجر)',
            ],
        ];
    }
      /**
     * مصادر الزيارات (QR menu access) + معدل التحويل إلى طلبات.
     */
    public function trafficAndConversion(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $logsQuery = MenuAccessLog::query()
            ->whereBetween('menu_access_logs.created_at', [$range['from'], $range['to']]);

        if (! empty($filters['branch_id'])) {
            $logsQuery->whereHas('qrCode', fn ($q) => $q->where('branch_id', $filters['branch_id']));
        }

        $totals = (clone $logsQuery)
            ->selectRaw('
                COUNT(*) as total_visits,
                SUM(CASE WHEN allowed = 1 THEN 1 ELSE 0 END) as allowed_visits,
                SUM(CASE WHEN allowed = 0 THEN 1 ELSE 0 END) as denied_visits
            ')
            ->first();

        $totalVisits = (int) ($totals->total_visits ?? 0);
        $allowedVisits = (int) ($totals->allowed_visits ?? 0);
        $deniedVisits = (int) ($totals->denied_visits ?? 0);

        $byMethod = (clone $logsQuery)
            ->selectRaw('
                access_method,
                COUNT(*) as total,
                SUM(CASE WHEN allowed = 1 THEN 1 ELSE 0 END) as allowed,
                SUM(CASE WHEN allowed = 0 THEN 1 ELSE 0 END) as denied
            ')
            ->groupBy('access_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'access_method' => $row->access_method,
                'total' => (int) $row->total,
                'allowed' => (int) $row->allowed,
                'denied' => (int) $row->denied,
                'allow_rate' => (int) $row->total > 0
                    ? round(((int) $row->allowed / (int) $row->total) * 100, 2)
                    : 0.0,
            ])
            ->values()
            ->all();

        $ordersQuery = Order::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereNotNull('qr_code_id');

        if (! empty($filters['branch_id'])) {
            $ordersQuery->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['order_type'])) {
            $ordersQuery->where('order_type', $filters['order_type']);
        }

        $ordersFromQr = (clone $ordersQuery)->count();
        $ordersPaidFromQr = (clone $ordersQuery)
            ->whereIn('status', [
                Order::STATUS_PAID,
                Order::STATUS_SERVED,
                Order::STATUS_REFUNDED,
            ])
            ->count();

        $conversionRate = $allowedVisits > 0
            ? round(($ordersFromQr / $allowedVisits) * 100, 2)
            : 0.0;
        $paidConversionRate = $allowedVisits > 0
            ? round(($ordersPaidFromQr / $allowedVisits) * 100, 2)
            : 0.0;

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $visitSeries = (clone $logsQuery)
                ->selectRaw('
                    DATE(created_at) as date,
                    COUNT(*) as total_visits,
                    SUM(CASE WHEN allowed = 1 THEN 1 ELSE 0 END) as allowed_visits
                ')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->keyBy('date');

            $orderSeries = (clone $ordersQuery)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->pluck('orders_count', 'date');

            $dates = $visitSeries->keys()->merge($orderSeries->keys())->unique()->sort()->values();
            foreach ($dates as $date) {
                $v = $visitSeries->get($date);
                $allowed = $v ? (int) $v->allowed_visits : 0;
                $orders = (int) ($orderSeries[$date] ?? 0);
                $series[] = [
                    'date' => $date,
                    'total_visits' => $v ? (int) $v->total_visits : 0,
                    'allowed_visits' => $allowed,
                    'orders_from_qr' => $orders,
                    'conversion_rate' => $allowed > 0
                        ? round(($orders / $allowed) * 100, 2)
                        : 0.0,
                ];
            }
        }

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'total_visits' => $totalVisits,
                'allowed_visits' => $allowedVisits,
                'denied_visits' => $deniedVisits,
                'allow_rate' => $totalVisits > 0
                    ? round(($allowedVisits / $totalVisits) * 100, 2)
                    : 0.0,
                'orders_from_qr' => $ordersFromQr,
                'orders_paid_from_qr' => $ordersPaidFromQr,
                'conversion_rate' => $conversionRate,
                'paid_conversion_rate' => $paidConversionRate,
            ],
            'by_access_method' => $byMethod,
            'series' => $series,
            'notes' => [
                'sources' => 'geofence | manual_code من menu_access_logs',
                'conversion' => 'طلبات لها qr_code_id ÷ الزيارات المسموحة (allowed)',
            ],
        ];
    }
      /**
     * تقرير نقاط الولاء: المكتسبة، المستخدمة، المستردة، المنتهية، الملغاة.
     */
    public function loyaltyPointsReport(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $query = LoyaltyPointsTransaction::query()
            ->whereBetween('created_at', [$range['from'], $range['to']]);

        if (! empty($filters['branch_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('order', fn ($oq) => $oq->where('branch_id', $filters['branch_id']))
                    ->orWhereNull('order_id');
            });
        }

        $totals = (clone $query)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN type = ? THEN points ELSE 0 END), 0) as earned,
                COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as redeemed,
                COALESCE(SUM(CASE WHEN type = ? THEN points ELSE 0 END), 0) as refunded,
                COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as expired,
                COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as revoked,
                COUNT(*) as transactions_count
            ', [
                LoyaltyPointsTransaction::TYPE_EARN,
                LoyaltyPointsTransaction::TYPE_REDEEM,
                LoyaltyPointsTransaction::TYPE_REFUND,
                LoyaltyPointsTransaction::TYPE_EXPIRE,
                LoyaltyPointsTransaction::TYPE_REVOKE,
            ])
            ->first();

        $earned = (int) ($totals->earned ?? 0);
        $redeemed = (int) ($totals->redeemed ?? 0);
        $refunded = (int) ($totals->refunded ?? 0);
        $expired = (int) ($totals->expired ?? 0);
        $revoked = (int) ($totals->revoked ?? 0);
        $net = $earned + $refunded - $redeemed - $expired - $revoked;

        $byType = (clone $query)
            ->selectRaw('
                type,
                COUNT(*) as count,
                COALESCE(SUM(points), 0) as points_sum,
                COALESCE(SUM(ABS(points)), 0) as points_abs
            ')
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'count' => (int) $row->count,
                'points_sum' => (int) $row->points_sum,
                'points_abs' => (int) $row->points_abs,
            ])
            ->values()
            ->all();

        $balanceStats = Customer::query()
            ->selectRaw('
                COUNT(*) as customers_with_account,
                COALESCE(SUM(loyalty_points_balance), 0) as total_balance,
                COALESCE(AVG(loyalty_points_balance), 0) as avg_balance,
                COALESCE(MAX(loyalty_points_balance), 0) as max_balance
            ')
            ->first();

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $series = (clone $query)
                ->selectRaw('
                    DATE(created_at) as date,
                    COALESCE(SUM(CASE WHEN type = ? THEN points ELSE 0 END), 0) as earned,
                    COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as redeemed,
                    COALESCE(SUM(CASE WHEN type = ? THEN points ELSE 0 END), 0) as refunded,
                    COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as expired,
                    COALESCE(SUM(CASE WHEN type = ? THEN ABS(points) ELSE 0 END), 0) as revoked
                ', [
                    LoyaltyPointsTransaction::TYPE_EARN,
                    LoyaltyPointsTransaction::TYPE_REDEEM,
                    LoyaltyPointsTransaction::TYPE_REFUND,
                    LoyaltyPointsTransaction::TYPE_EXPIRE,
                    LoyaltyPointsTransaction::TYPE_REVOKE,
                ])
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'earned' => (int) $row->earned,
                    'redeemed' => (int) $row->redeemed,
                    'refunded' => (int) $row->refunded,
                    'expired' => (int) $row->expired,
                    'revoked' => (int) $row->revoked,
                    'net' => (int) $row->earned + (int) $row->refunded
                        - (int) $row->redeemed - (int) $row->expired - (int) $row->revoked,
                ])
                ->values()
                ->all();
        }

        $topEarners = (clone $query)
            ->where('type', LoyaltyPointsTransaction::TYPE_EARN)
            ->selectRaw('customer_id, SUM(points) as points')
            ->groupBy('customer_id')
            ->orderByDesc('points')
            ->limit(10)
            ->get();

        $topRedeemer = (clone $query)
            ->where('type', LoyaltyPointsTransaction::TYPE_REDEEM)
            ->selectRaw('customer_id, SUM(ABS(points)) as points')
            ->groupBy('customer_id')
            ->orderByDesc('points')
            ->limit(10)
            ->get();

        $customerIds = $topEarners->pluck('customer_id')
            ->merge($topRedeemer->pluck('customer_id'))
            ->unique()
            ->filter();

        $names = $customerIds->isNotEmpty()
            ? Customer::query()->whereIn('id', $customerIds)->pluck('name', 'id')
            : collect();

        $mapTop = function ($rows) use ($names) {
            return $rows->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'name' => $names[$row->customer_id] ?? null,
                'points' => (int) $row->points,
            ])->values()->all();
        };

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'transactions_count' => (int) ($totals->transactions_count ?? 0),
                'earned' => $earned,
                'redeemed' => $redeemed,
                'refunded' => $refunded,
                'expired' => $expired,
                'revoked' => $revoked,
                'net' => $net,
            ],
            'by_type' => $byType,
            'current_balances' => [
                'customers_with_account' => (int) ($balanceStats->customers_with_account ?? 0),
                'total_balance' => (int) ($balanceStats->total_balance ?? 0),
                'avg_balance' => round((float) ($balanceStats->avg_balance ?? 0), 2),
                'max_balance' => (int) ($balanceStats->max_balance ?? 0),
            ],
            'top_earners' => $mapTop($topEarners),
            'top_redeemers' => $mapTop($topRedeemer),
            'series' => $series,
        ];
    }
      /**
     * شرائح العملاء + المتوقفون عن الشراء.
     */
    public function customerSegmentsAndChurn(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $asOf = $range['to']->copy();
        $paidStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_SERVED,
            Order::STATUS_REFUNDED,
        ];

        $ordersQuery = Order::query()
            ->whereIn('status', $paidStatuses)
            ->whereNotNull('customer_id')
            ->where('created_at', '<=', $asOf);

        if (! empty($filters['branch_id'])) {
            $ordersQuery->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['order_type'])) {
            $ordersQuery->where('order_type', $filters['order_type']);
        }

        $agg = (clone $ordersQuery)
            ->selectRaw('
                customer_id,
                COUNT(*) as orders_count,
                COALESCE(SUM(COALESCE(total_inc_vat, total_amount)), 0) as total_spent,
                MAX(created_at) as last_order_at,
                MIN(created_at) as first_order_at
            ')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        if ($agg->isEmpty()) {
            return [
                'period' => $range['period'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'as_of' => $asOf->toDateString(),
                'filters' => [
                    'branch_id' => $filters['branch_id'] ?? null,
                    'order_type' => $filters['order_type'] ?? null,
                ],
                'totals' => [
                    'customers' => 0,
                    'active' => 0,
                    'at_risk' => 0,
                    'dormant' => 0,
                    'churned' => 0,
                ],
                'segments' => [],
                'by_tier' => [],
                'churned_customers' => [],
            ];
        }

        $customers = Customer::query()
            ->whereIn('id', $agg->keys())
            ->get(['id', 'name', 'phone', 'tier', 'loyalty_points_balance', 'total_spent'])
            ->keyBy('id');

        $segments = [
            'active' => [],
            'at_risk' => [],
            'dormant' => [],
            'churned' => [],
        ];

        $byTier = [];

        foreach ($agg as $customerId => $row) {
            $lastAt = Carbon::parse($row->last_order_at);
            $daysSince = $lastAt->diffInDays($asOf);
            $ordersCount = (int) $row->orders_count;
            $spent = round((float) $row->total_spent, 2);

            if ($daysSince <= 30) {
                $segment = 'active';
            } elseif ($daysSince <= 60) {
                $segment = 'at_risk';
            } elseif ($daysSince <= 90) {
                $segment = 'dormant';
            } else {
                $segment = 'churned';
            }

            $customer = $customers->get($customerId);
            $tier = $customer->tier ?? 'none';

            $entry = [
                'customer_id' => (int) $customerId,
                'name' => $customer->name ?? null,
                'phone' => $customer->phone ?? null,
                'tier' => $tier,
                'orders_count' => $ordersCount,
                'total_spent' => $spent,
                'last_order_at' => $lastAt->toDateTimeString(),
                'days_since_last_order' => (int) $daysSince,
                'segment' => $segment,
            ];

            $segments[$segment][] = $entry;

            if (! isset($byTier[$tier])) {
                $byTier[$tier] = [
                    'tier' => $tier,
                    'customers' => 0,
                    'orders_count' => 0,
                    'total_spent' => 0.0,
                    'active' => 0,
                    'at_risk' => 0,
                    'dormant' => 0,
                    'churned' => 0,
                ];
            }
            $byTier[$tier]['customers']++;
            $byTier[$tier]['orders_count'] += $ordersCount;
            $byTier[$tier]['total_spent'] += $spent;
            $byTier[$tier][$segment]++;
        }

        $totalCustomers = $agg->count();
        $segmentSummary = [];
        foreach (['active', 'at_risk', 'dormant', 'churned'] as $seg) {
            $count = count($segments[$seg]);
            $segSpent = round(array_sum(array_column($segments[$seg], 'total_spent')), 2);
            $segmentSummary[] = [
                'segment' => $seg,
                'customers' => $count,
                'percentage' => $totalCustomers > 0
                    ? round(($count / $totalCustomers) * 100, 2)
                    : 0.0,
                'total_spent' => $segSpent,
            ];
        }

        $tierList = array_values(array_map(function ($row) {
            $row['total_spent'] = round($row['total_spent'], 2);

            return $row;
        }, $byTier));
        usort($tierList, fn ($a, $b) => $b['total_spent'] <=> $a['total_spent']);

        $churnedList = $segments['churned'];
        usort($churnedList, fn ($a, $b) => $b['total_spent'] <=> $a['total_spent']);
        $churnedList = array_slice($churnedList, 0, 20);

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'as_of' => $asOf->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
            ],
            'definitions' => [
                'active' => 'آخر طلب خلال 30 يوم',
                'at_risk' => 'آخر طلب قبل 31–60 يوم',
                'dormant' => 'آخر طلب قبل 61–90 يوم',
                'churned' => 'آخر طلب قبل أكثر من 90 يوم',
            ],
            'totals' => [
                'customers' => $totalCustomers,
                'active' => count($segments['active']),
                'at_risk' => count($segments['at_risk']),
                'dormant' => count($segments['dormant']),
                'churned' => count($segments['churned']),
            ],
            'segments' => $segmentSummary,
            'by_tier' => $tierList,
            'churned_customers' => $churnedList,
        ];
    }
      /**
     * تكرار الشراء + نسبة الاحتفاظ بالعملاء.
     */
    public function purchaseFrequencyAndRetention(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $paidStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_SERVED,
            Order::STATUS_REFUNDED,
        ];

        $applyFilters = function ($query) use ($filters, $paidStatuses) {
            $query->whereIn('status', $paidStatuses)->whereNotNull('customer_id');
            if (! empty($filters['branch_id'])) {
                $query->where('branch_id', $filters['branch_id']);
            }
            if (! empty($filters['order_type'])) {
                $query->where('order_type', $filters['order_type']);
            }

            return $query;
        };

        $currentOrders = $applyFilters(
            Order::query()->whereBetween('created_at', [$range['from'], $range['to']])
        )->get(['customer_id', 'created_at', 'total_inc_vat', 'total_amount']);

        $ordersPerCustomer = [];
        foreach ($currentOrders as $order) {
            $cid = (int) $order->customer_id;
            $ordersPerCustomer[$cid] = ($ordersPerCustomer[$cid] ?? 0) + 1;
        }

        $activeCustomers = count($ordersPerCustomer);
        $totalOrders = $currentOrders->count();
        $purchaseFrequency = $activeCustomers > 0
            ? round($totalOrders / $activeCustomers, 2)
            : 0.0;

        $repeatCustomers = count(array_filter($ordersPerCustomer, fn ($c) => $c > 1));
        $repeatPurchaseRate = $activeCustomers > 0
            ? round(($repeatCustomers / $activeCustomers) * 100, 2)
            : 0.0;

        $frequencyDistribution = [];
        foreach ($ordersPerCustomer as $count) {
            $key = $count >= 10 ? '10+' : (string) $count;
            $frequencyDistribution[$key] = ($frequencyDistribution[$key] ?? 0) + 1;
        }
        ksort($frequencyDistribution, SORT_NATURAL);
        $distribution = [];
        foreach ($frequencyDistribution as $bucket => $count) {
            $distribution[] = [
                'orders_count' => $bucket,
                'customers' => $count,
                'percentage' => $activeCustomers > 0
                    ? round(($count / $activeCustomers) * 100, 2)
                    : 0.0,
            ];
        }

        $durationSeconds = $range['from']->diffInSeconds($range['to']);
        $prevTo = $range['from']->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds($durationSeconds);

        $prevCustomerIds = $applyFilters(
            Order::query()->whereBetween('created_at', [$prevFrom, $prevTo])
        )->distinct()->pluck('customer_id')->map(fn ($id) => (int) $id)->all();

        $currentCustomerSet = array_flip(array_keys($ordersPerCustomer));
        $retained = 0;
        foreach ($prevCustomerIds as $cid) {
            if (isset($currentCustomerSet[$cid])) {
                $retained++;
            }
        }
        $prevCount = count($prevCustomerIds);
        $retentionRate = $prevCount > 0
            ? round(($retained / $prevCount) * 100, 2)
            : 0.0;

        $churned = $prevCount - $retained;
        $churnRate = $prevCount > 0
            ? round(($churned / $prevCount) * 100, 2)
            : 0.0;

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $byDate = [];
            foreach ($currentOrders as $order) {
                $date = Carbon::parse($order->created_at)->toDateString();
                $cid = (int) $order->customer_id;
                if (! isset($byDate[$date])) {
                    $byDate[$date] = [];
                }
                $byDate[$date][$cid] = ($byDate[$date][$cid] ?? 0) + 1;
            }
            ksort($byDate);
            foreach ($byDate as $date => $perCustomer) {
                $cust = count($perCustomer);
                $ords = array_sum($perCustomer);
                $series[] = [
                    'date' => $date,
                    'active_customers' => $cust,
                    'orders_count' => $ords,
                    'purchase_frequency' => $cust > 0 ? round($ords / $cust, 2) : 0.0,
                    'repeat_customers' => count(array_filter($perCustomer, fn ($c) => $c > 1)),
                ];
            }
        }

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'previous_period' => [
                'from' => $prevFrom->toDateString(),
                'to' => $prevTo->toDateString(),
            ],
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'active_customers' => $activeCustomers,
                'orders_count' => $totalOrders,
                'purchase_frequency' => $purchaseFrequency,
                'repeat_customers' => $repeatCustomers,
                'repeat_purchase_rate' => $repeatPurchaseRate,
                'previous_period_customers' => $prevCount,
                'retained_customers' => $retained,
                'retention_rate' => $retentionRate,
                'churned_customers' => $churned,
                'churn_rate' => $churnRate,
            ],
            'frequency_distribution' => $distribution,
            'series' => $series,
        ];
    }
  	    /**
     * تقرير العملاء الجدد مقابل المتكررين في الفترة.
     *
     * - جديد: أول طلب مدفوع/مكتمل له يقع داخل الفترة
     * - متكرر: لديه طلب سابق قبل بداية الفترة، وعمل طلب في الفترة
     */
    public function customersNewVsReturning(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $paidStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_SERVED,
            Order::STATUS_REFUNDED,
        ];

        $ordersInPeriod = Order::query()
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->whereIn('status', $paidStatuses)
            ->whereNotNull('customer_id');

        if (! empty($filters['branch_id'])) {
            $ordersInPeriod->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['order_type'])) {
            $ordersInPeriod->where('order_type', $filters['order_type']);
        }

        $customerIds = (clone $ordersInPeriod)->distinct()->pluck('customer_id');

        if ($customerIds->isEmpty()) {
            return [
                'period' => $range['period'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'filters' => [
                    'branch_id' => $filters['branch_id'] ?? null,
                    'order_type' => $filters['order_type'] ?? null,
                    'group_by' => $filters['group_by'] ?? null,
                ],
                'totals' => [
                    'customers_with_orders' => 0,
                    'new_customers' => 0,
                    'returning_customers' => 0,
                    'new_percentage' => 0.0,
                    'returning_percentage' => 0.0,
                    'orders_count' => 0,
                    'orders_by_new' => 0,
                    'orders_by_returning' => 0,
                    'revenue_total' => 0.0,
                    'revenue_by_new' => 0.0,
                    'revenue_by_returning' => 0.0,
                ],
                'series' => [],
            ];
        }

        $firstOrderQuery = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', $paidStatuses)
            ->whereNotNull('customer_id');

        if (! empty($filters['branch_id'])) {
            $firstOrderQuery->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['order_type'])) {
            $firstOrderQuery->where('order_type', $filters['order_type']);
        }

        $firstOrders = $firstOrderQuery
            ->selectRaw('customer_id, MIN(created_at) as first_order_at')
            ->groupBy('customer_id')
            ->pluck('first_order_at', 'customer_id');

        $newIds = [];
        $returningIds = [];

        foreach ($customerIds as $cid) {
            $firstAt = isset($firstOrders[$cid]) ? Carbon::parse($firstOrders[$cid]) : null;
            if ($firstAt && $firstAt->gte($range['from']) && $firstAt->lte($range['to'])) {
                $newIds[] = (int) $cid;
            } else {
                $returningIds[] = (int) $cid;
            }
        }

        $newIds = array_values(array_unique($newIds));
        $returningIds = array_values(array_unique($returningIds));

        $periodOrders = (clone $ordersInPeriod)
            ->get(['id', 'customer_id', 'total_amount', 'total_inc_vat', 'created_at']);

        $ordersByNew = 0;
        $ordersByReturning = 0;
        $revenueByNew = 0.0;
        $revenueByReturning = 0.0;
        $newSet = array_flip($newIds);
        $retSet = array_flip($returningIds);

        foreach ($periodOrders as $order) {
            $amount = (float) ($order->total_inc_vat ?? $order->total_amount ?? 0);
            $cid = (int) $order->customer_id;
            if (isset($newSet[$cid])) {
                $ordersByNew++;
                $revenueByNew += $amount;
            } elseif (isset($retSet[$cid])) {
                $ordersByReturning++;
                $revenueByReturning += $amount;
            }
        }

        $totalCustomers = count($newIds) + count($returningIds);
        $ordersCount = $periodOrders->count();
        $revenueTotal = $revenueByNew + $revenueByReturning;

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $byDate = [];
            foreach ($periodOrders as $order) {
                $date = Carbon::parse($order->created_at)->toDateString();
                $cid = (int) $order->customer_id;
                if (! isset($byDate[$date])) {
                    $byDate[$date] = [
                        'new_customer_ids' => [],
                        'returning_customer_ids' => [],
                        'orders_by_new' => 0,
                        'orders_by_returning' => 0,
                        'revenue_by_new' => 0.0,
                        'revenue_by_returning' => 0.0,
                    ];
                }
                $amount = (float) ($order->total_inc_vat ?? $order->total_amount ?? 0);
                if (isset($newSet[$cid])) {
                    $byDate[$date]['new_customer_ids'][$cid] = true;
                    $byDate[$date]['orders_by_new']++;
                    $byDate[$date]['revenue_by_new'] += $amount;
                } elseif (isset($retSet[$cid])) {
                    $byDate[$date]['returning_customer_ids'][$cid] = true;
                    $byDate[$date]['orders_by_returning']++;
                    $byDate[$date]['revenue_by_returning'] += $amount;
                }
            }
            ksort($byDate);
            foreach ($byDate as $date => $row) {
                $series[] = [
                    'date' => $date,
                    'new_customers' => count($row['new_customer_ids']),
                    'returning_customers' => count($row['returning_customer_ids']),
                    'orders_by_new' => $row['orders_by_new'],
                    'orders_by_returning' => $row['orders_by_returning'],
                    'revenue_by_new' => round($row['revenue_by_new'], 2),
                    'revenue_by_returning' => round($row['revenue_by_returning'], 2),
                ];
            }
        }

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'customers_with_orders' => $totalCustomers,
                'new_customers' => count($newIds),
                'returning_customers' => count($returningIds),
                'new_percentage' => $totalCustomers > 0
                    ? round((count($newIds) / $totalCustomers) * 100, 2)
                    : 0.0,
                'returning_percentage' => $totalCustomers > 0
                    ? round((count($returningIds) / $totalCustomers) * 100, 2)
                    : 0.0,
                'orders_count' => $ordersCount,
                'orders_by_new' => $ordersByNew,
                'orders_by_returning' => $ordersByReturning,
                'revenue_total' => round($revenueTotal, 2),
                'revenue_by_new' => round($revenueByNew, 2),
                'revenue_by_returning' => round($revenueByReturning, 2),
            ],
            'series' => $series,
        ];
    }
    /**
     * يحسب نطاق التاريخ من period أو from/to.
     *
     * @return array{from: Carbon, to: Carbon, period: string}
     */
    public function resolveDateRange(?string $period, ?string $from, ?string $to): array
    {
        $period = $period ?: 'custom';

        if ($period === 'custom') {
            if (! $from || ! $to) {
                throw new InvalidArgumentException('from و to مطلوبان عند period=custom');
            }

            return [
                'from' => Carbon::parse($from)->startOfDay(),
                'to' => Carbon::parse($to)->endOfDay(),
                'period' => 'custom',
            ];
        }

        $now = now();

        return match ($period) {
            'daily' => [
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy()->endOfDay(),
                'period' => 'daily',
            ],
            'weekly' => [
                'from' => $now->copy()->startOfWeek(),
                'to' => $now->copy()->endOfWeek(),
                'period' => 'weekly',
            ],
            'monthly' => [
                'from' => $now->copy()->startOfMonth(),
                'to' => $now->copy()->endOfMonth(),
                'period' => 'monthly',
            ],
            default => throw new InvalidArgumentException(
                'period غير صالح. القيم المسموحة: daily, weekly, monthly, custom'
            ),
        };
    }

    /**
     * استعلام أساسي على الطلبات مع فلاتر الفترة والفرع ونوع الطلب.
     */
    public function baseOrdersQuery(array $filters): Builder
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $query = Order::query()
            ->whereBetween('orders.created_at', [$range['from'], $range['to']]);

        if (! empty($filters['branch_id'])) {
            $query->where('orders.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['order_type'])) {
            $query->where('orders.order_type', $filters['order_type']);
        }

        return $query;
    }

    /**
     * تقرير الخصومات والضريبة
     */
    public function discountsAndTax(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $base = $this->baseOrdersQuery($filters);

        $paidStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_SERVED,
            Order::STATUS_REFUNDED,
        ];

        $scoped = (clone $base)->whereIn('orders.status', $paidStatuses);

        $totals = (clone $scoped)
            ->selectRaw('
                COUNT(*) as orders_count,
                COALESCE(SUM(coupon_discount), 0) as total_coupon_discount,
                COALESCE(SUM(redeemed_amount), 0) as total_loyalty_discount,
                COALESCE(SUM(coupon_discount), 0) + COALESCE(SUM(redeemed_amount), 0) as total_discounts,
                COALESCE(SUM(subtotal_ex_vat), 0) as total_subtotal_ex_vat,
                COALESCE(SUM(vat_amount), 0) as total_vat,
                COALESCE(SUM(total_inc_vat), 0) as total_inc_vat,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $byBranch = (clone $scoped)
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->selectRaw('
                orders.branch_id,
                branches.name_ar as branch_name_ar,
                branches.name_en as branch_name_en,
                COUNT(*) as orders_count,
                COALESCE(SUM(orders.coupon_discount), 0) as total_coupon_discount,
                COALESCE(SUM(orders.redeemed_amount), 0) as total_loyalty_discount,
                COALESCE(SUM(orders.coupon_discount), 0) + COALESCE(SUM(orders.redeemed_amount), 0) as total_discounts,
                COALESCE(SUM(orders.subtotal_ex_vat), 0) as total_subtotal_ex_vat,
                COALESCE(SUM(orders.vat_amount), 0) as total_vat,
                COALESCE(SUM(orders.total_inc_vat), 0) as total_inc_vat
            ')
            ->groupBy('orders.branch_id', 'branches.name_ar', 'branches.name_en')
            ->orderByDesc('total_discounts')
            ->get()
            ->map(fn ($row) => $this->formatMoneyRow($row->toArray()))
            ->values()
            ->all();

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $series = (clone $scoped)
                ->selectRaw('
                    DATE(orders.created_at) as date,
                    COUNT(*) as orders_count,
                    COALESCE(SUM(coupon_discount), 0) as total_coupon_discount,
                    COALESCE(SUM(redeemed_amount), 0) as total_loyalty_discount,
                    COALESCE(SUM(coupon_discount), 0) + COALESCE(SUM(redeemed_amount), 0) as total_discounts,
                    COALESCE(SUM(vat_amount), 0) as total_vat,
                    COALESCE(SUM(total_inc_vat), 0) as total_inc_vat
                ')
                ->groupBy(DB::raw('DATE(orders.created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => $this->formatMoneyRow($row->toArray()))
                ->values()
                ->all();
        }

        $cancellations = (clone $base)
            ->where('orders.status', Order::STATUS_CANCELLED)
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(coupon_discount), 0) as coupon_discount_lost,
                COALESCE(SUM(redeemed_amount), 0) as loyalty_discount_lost
            ')
            ->first();

        $refunds = (clone $base)
            ->where('status', Order::STATUS_REFUNDED)
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(vat_amount), 0) as vat_amount
            ')
            ->first();

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => $this->formatMoneyRow([
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'total_coupon_discount' => (float) ($totals->total_coupon_discount ?? 0),
                'total_loyalty_discount' => (float) ($totals->total_loyalty_discount ?? 0),
                'total_discounts' => (float) ($totals->total_discounts ?? 0),
                'total_subtotal_ex_vat' => (float) ($totals->total_subtotal_ex_vat ?? 0),
                'total_vat' => (float) ($totals->total_vat ?? 0),
                'total_inc_vat' => (float) ($totals->total_inc_vat ?? 0),
                'total_amount' => (float) ($totals->total_amount ?? 0),
            ]),
            'by_branch' => $byBranch,
            'series' => $series,
            'cancellations' => $this->formatMoneyRow([
                'count' => (int) ($cancellations->count ?? 0),
                'total_amount' => (float) ($cancellations->total_amount ?? 0),
                'coupon_discount_lost' => (float) ($cancellations->coupon_discount_lost ?? 0),
                'loyalty_discount_lost' => (float) ($cancellations->loyalty_discount_lost ?? 0),
            ]),
            'refunds' => $this->formatMoneyRow([
                'count' => (int) ($refunds->count ?? 0),
                'total_amount' => (float) ($refunds->total_amount ?? 0),
                'vat_amount' => (float) ($refunds->vat_amount ?? 0),
            ]),
        ];
    }
  	
  	    /**
     * تقرير عمليات الدفع الأونلاين: ناجحة / فاشلة / قيد الانتظار + أسباب الفشل.
     */
    public function paymentOperations(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $query = OnlinePayment::query()
            ->whereBetween('online_payments.created_at', [$range['from'], $range['to']]);

        if (! empty($filters['branch_id'])) {
            $query->whereHas('order', fn ($q) => $q->where('branch_id', $filters['branch_id']));
        }

        if (! empty($filters['order_type'])) {
            $query->whereHas('order', fn ($q) => $q->where('order_type', $filters['order_type']));
        }

        if (! empty($filters['gateway'])) {
            $query->where('gateway', $filters['gateway']);
        }

        $totals = (clone $query)
            ->selectRaw('
                COUNT(*) as total_attempts,
                COALESCE(SUM(amount), 0) as total_amount_attempted,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as success_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as failed_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as initiated_count,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as initiated_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded_count,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as refunded_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refund_failed_count
            ', [
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_FAILED,
                OnlinePayment::STATUS_FAILED,
                OnlinePayment::STATUS_INITIATED,
                OnlinePayment::STATUS_INITIATED,
                OnlinePayment::STATUS_REFUNDED,
                OnlinePayment::STATUS_REFUNDED,
                OnlinePayment::STATUS_REFUND_FAILED,
            ])
            ->first();

        $totalAttempts = (int) ($totals->total_attempts ?? 0);
        $successCount = (int) ($totals->success_count ?? 0);
        $failedCount = (int) ($totals->failed_count ?? 0);
        $decided = $successCount + $failedCount;
        $successRate = $decided > 0 ? round(($successCount / $decided) * 100, 2) : 0.0;
        $failureRate = $decided > 0 ? round(($failedCount / $decided) * 100, 2) : 0.0;

        $byStatus = (clone $query)
            ->selectRaw('
                status,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as amount
            ')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'amount' => round((float) $row->amount, 2),
            ])
            ->values()
            ->all();

        $byGateway = (clone $query)
            ->selectRaw('
                gateway,
                COUNT(*) as total_attempts,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as success_amount,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as failed_amount
            ', [
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_FAILED,
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_FAILED,
            ])
            ->groupBy('gateway')
            ->orderByDesc('total_attempts')
            ->get()
            ->map(function ($row) {
                $s = (int) $row->success_count;
                $f = (int) $row->failed_count;
                $d = $s + $f;

                return [
                    'gateway' => $row->gateway,
                    'total_attempts' => (int) $row->total_attempts,
                    'success_count' => $s,
                    'failed_count' => $f,
                    'success_amount' => round((float) $row->success_amount, 2),
                    'failed_amount' => round((float) $row->failed_amount, 2),
                    'success_rate' => $d > 0 ? round(($s / $d) * 100, 2) : 0.0,
                ];
            })
            ->values()
            ->all();

        $failedPayments = (clone $query)
            ->where('status', OnlinePayment::STATUS_FAILED)
            ->select(['id', 'order_id', 'gateway', 'amount', 'gateway_response', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $reasonCounts = [];
        $recentFailures = [];

        foreach ($failedPayments as $payment) {
            $reason = $this->extractFailureReason($payment->gateway_response);
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

            if (count($recentFailures) < 20) {
                $recentFailures[] = [
                    'id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'gateway' => $payment->gateway,
                    'amount' => round((float) $payment->amount, 2),
                    'reason' => $reason,
                    'created_at' => optional($payment->created_at)->toDateTimeString(),
                ];
            }
        }

        arsort($reasonCounts);
        $failureReasons = [];
        foreach ($reasonCounts as $reason => $count) {
            $failureReasons[] = [
                'reason' => $reason,
                'count' => $count,
                'percentage' => $failedCount > 0 ? round(($count / $failedCount) * 100, 2) : 0.0,
            ];
        }

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $series = (clone $query)
                ->selectRaw('
                    DATE(created_at) as date,
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count,
                    COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as success_amount,
                    COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as failed_amount
                ', [
                    OnlinePayment::STATUS_PAID,
                    OnlinePayment::STATUS_FAILED,
                    OnlinePayment::STATUS_PAID,
                    OnlinePayment::STATUS_FAILED,
                ])
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'total_attempts' => (int) $row->total_attempts,
                    'success_count' => (int) $row->success_count,
                    'failed_count' => (int) $row->failed_count,
                    'success_amount' => round((float) $row->success_amount, 2),
                    'failed_amount' => round((float) $row->failed_amount, 2),
                ])
                ->values()
                ->all();
        }

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'gateway' => $filters['gateway'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'totals' => [
                'total_attempts' => $totalAttempts,
                'total_amount_attempted' => round((float) ($totals->total_amount_attempted ?? 0), 2),
                'success_count' => $successCount,
                'success_amount' => round((float) ($totals->success_amount ?? 0), 2),
                'failed_count' => $failedCount,
                'failed_amount' => round((float) ($totals->failed_amount ?? 0), 2),
                'initiated_count' => (int) ($totals->initiated_count ?? 0),
                'initiated_amount' => round((float) ($totals->initiated_amount ?? 0), 2),
                'refunded_count' => (int) ($totals->refunded_count ?? 0),
                'refunded_amount' => round((float) ($totals->refunded_amount ?? 0), 2),
                'refund_failed_count' => (int) ($totals->refund_failed_count ?? 0),
                'success_rate' => $successRate,
                'failure_rate' => $failureRate,
            ],
            'by_status' => $byStatus,
            'by_gateway' => $byGateway,
            'failure_reasons' => $failureReasons,
            'recent_failures' => $recentFailures,
            'series' => $series,
        ];
    }

    /**
     * استخراج سبب الفشل من gateway_response (HyperPay / MPGS وأشكال شائعة).
     */
    private function extractFailureReason(mixed $gatewayResponse): string
    {
        if (! is_array($gatewayResponse) && ! is_object($gatewayResponse)) {
            return 'unknown';
        }

        $data = json_decode(json_encode($gatewayResponse), true);
        if (! is_array($data)) {
            return 'unknown';
        }

        $candidates = [
            data_get($data, 'result.description'),
            data_get($data, 'last_result.result.description'),
            data_get($data, 'result.code'),
            data_get($data, 'last_result.result.code'),
            data_get($data, 'response.gatewayCode'),
            data_get($data, 'error.explanation'),
            data_get($data, 'error.message'),
            data_get($data, 'message'),
            data_get($data, 'error'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'unknown';
    }
      /**
     * تقرير زمن التجهيز وزمن تنفيذ الطلب من order_status_logs.
     *
     * - queue_minutes: pending → accepted
     * - prep_minutes: preparing → ready (أو accepted → ready)
     * - service_minutes: ready → served
     * - total_fulfillment_minutes: accepted → served|paid
     */
    public function fulfillmentTimes(array $filters): array
    {
        $range = $this->resolveDateRange(
            $filters['period'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        $ordersQuery = $this->baseOrdersQuery($filters)
            ->whereIn('status', [
                Order::STATUS_READY,
                Order::STATUS_SERVED,
                Order::STATUS_PAID,
                Order::STATUS_PREPARING,
                Order::STATUS_ACCEPTED,
            ]);

        $orderIds = (clone $ordersQuery)->pluck('id');

        if ($orderIds->isEmpty()) {
            return [
                'period' => $range['period'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'filters' => [
                    'branch_id' => $filters['branch_id'] ?? null,
                    'order_type' => $filters['order_type'] ?? null,
                    'group_by' => $filters['group_by'] ?? null,
                ],
                'sample_size' => 0,
                'averages' => [
                    'queue_minutes' => null,
                    'prep_minutes' => null,
                    'service_minutes' => null,
                    'total_fulfillment_minutes' => null,
                ],
                'medians' => [
                    'queue_minutes' => null,
                    'prep_minutes' => null,
                    'service_minutes' => null,
                    'total_fulfillment_minutes' => null,
                ],
                'by_branch' => [],
                'series' => [],
                'slowest_orders' => [],
            ];
        }

        $logs = OrderStatusLog::query()
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, status, MIN(created_at) as first_at')
            ->groupBy('order_id', 'status')
            ->get()
            ->groupBy('order_id');

        $ordersMeta = Order::query()
            ->whereIn('id', $orderIds)
            ->get(['id', 'branch_id', 'created_at', 'status'])
            ->keyBy('id');

        $branchNames = DB::table('branches')
            ->whereIn('id', $ordersMeta->pluck('branch_id')->unique()->filter())
            ->pluck(DB::raw('COALESCE(name_ar, name_en)'), 'id');

        $metrics = [];

        foreach ($logs as $orderId => $statusRows) {
            $times = [];
            foreach ($statusRows as $row) {
                $times[$row->status] = Carbon::parse($row->first_at);
            }

            $order = $ordersMeta->get($orderId);
            if (! $order) {
                continue;
            }

            $pendingAt = $times[Order::STATUS_PENDING] ?? Carbon::parse($order->created_at);
            $acceptedAt = $times[Order::STATUS_ACCEPTED] ?? null;
            $preparingAt = $times[Order::STATUS_PREPARING] ?? null;
            $readyAt = $times[Order::STATUS_READY] ?? null;
            $servedAt = $times[Order::STATUS_SERVED] ?? null;
            $paidAt = $times[Order::STATUS_PAID] ?? null;

            $endAt = $servedAt ?? $paidAt;

            $queue = ($acceptedAt && $pendingAt)
                ? max(0, $pendingAt->diffInSeconds($acceptedAt) / 60)
                : null;

            $prepStart = $preparingAt ?? $acceptedAt;
            $prep = ($readyAt && $prepStart)
                ? max(0, $prepStart->diffInSeconds($readyAt) / 60)
                : null;

            $service = ($servedAt && $readyAt)
                ? max(0, $readyAt->diffInSeconds($servedAt) / 60)
                : null;

            $total = ($endAt && $acceptedAt)
                ? max(0, $acceptedAt->diffInSeconds($endAt) / 60)
                : null;

            if ($queue === null && $prep === null && $service === null && $total === null) {
                continue;
            }

            $metrics[$orderId] = [
                'order_id' => (int) $orderId,
                'branch_id' => $order->branch_id,
                'date' => Carbon::parse($order->created_at)->toDateString(),
                'queue_minutes' => $queue !== null ? round($queue, 2) : null,
                'prep_minutes' => $prep !== null ? round($prep, 2) : null,
                'service_minutes' => $service !== null ? round($service, 2) : null,
                'total_fulfillment_minutes' => $total !== null ? round($total, 2) : null,
            ];
        }

        $values = array_values($metrics);
        $sampleSize = count($values);

        $avg = fn (string $key) => $this->avgNullable(array_column($values, $key));
        $med = fn (string $key) => $this->medianNullable(array_column($values, $key));

        $byBranchMap = [];
        foreach ($values as $row) {
            $bid = $row['branch_id'] ?? 0;
            $byBranchMap[$bid][] = $row;
        }
        $byBranch = [];
        foreach ($byBranchMap as $bid => $rows) {
            $byBranch[] = [
                'branch_id' => $bid ?: null,
                'branch_name' => $bid ? ($branchNames[$bid] ?? null) : null,
                'sample_size' => count($rows),
                'avg_queue_minutes' => $this->avgNullable(array_column($rows, 'queue_minutes')),
                'avg_prep_minutes' => $this->avgNullable(array_column($rows, 'prep_minutes')),
                'avg_service_minutes' => $this->avgNullable(array_column($rows, 'service_minutes')),
                'avg_total_fulfillment_minutes' => $this->avgNullable(array_column($rows, 'total_fulfillment_minutes')),
            ];
        }
        usort($byBranch, fn ($a, $b) => ($b['avg_total_fulfillment_minutes'] ?? 0) <=> ($a['avg_total_fulfillment_minutes'] ?? 0));

        $series = [];
        if (($filters['group_by'] ?? null) === 'day') {
            $byDate = [];
            foreach ($values as $row) {
                $byDate[$row['date']][] = $row;
            }
            ksort($byDate);
            foreach ($byDate as $date => $rows) {
                $series[] = [
                    'date' => $date,
                    'sample_size' => count($rows),
                    'avg_queue_minutes' => $this->avgNullable(array_column($rows, 'queue_minutes')),
                    'avg_prep_minutes' => $this->avgNullable(array_column($rows, 'prep_minutes')),
                    'avg_service_minutes' => $this->avgNullable(array_column($rows, 'service_minutes')),
                    'avg_total_fulfillment_minutes' => $this->avgNullable(array_column($rows, 'total_fulfillment_minutes')),
                ];
            }
        }

        $slowest = array_values(array_filter($values, fn ($r) => $r['total_fulfillment_minutes'] !== null));
        usort($slowest, fn ($a, $b) => $b['total_fulfillment_minutes'] <=> $a['total_fulfillment_minutes']);
        $slowest = array_slice($slowest, 0, 10);

        return [
            'period' => $range['period'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'filters' => [
                'branch_id' => $filters['branch_id'] ?? null,
                'order_type' => $filters['order_type'] ?? null,
                'group_by' => $filters['group_by'] ?? null,
            ],
            'sample_size' => $sampleSize,
            'averages' => [
                'queue_minutes' => $avg('queue_minutes'),
                'prep_minutes' => $avg('prep_minutes'),
                'service_minutes' => $avg('service_minutes'),
                'total_fulfillment_minutes' => $avg('total_fulfillment_minutes'),
            ],
            'medians' => [
                'queue_minutes' => $med('queue_minutes'),
                'prep_minutes' => $med('prep_minutes'),
                'service_minutes' => $med('service_minutes'),
                'total_fulfillment_minutes' => $med('total_fulfillment_minutes'),
            ],
            'by_branch' => $byBranch,
            'series' => $series,
            'slowest_orders' => $slowest,
        ];
    }

    private function avgNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, fn ($v) => $v !== null));
        if ($nums === []) {
            return null;
        }

        return round(array_sum($nums) / count($nums), 2);
    }

    private function medianNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, fn ($v) => $v !== null));
        if ($nums === []) {
            return null;
        }
        sort($nums, SORT_NUMERIC);
        $count = count($nums);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return round(($nums[$mid - 1] + $nums[$mid]) / 2, 2);
        }

        return round((float) $nums[$mid], 2);
    }

    private function formatMoneyRow(array $row): array
    {
        $moneyKeys = [
            'total_coupon_discount',
            'total_loyalty_discount',
            'total_discounts',
            'total_subtotal_ex_vat',
            'total_vat',
            'total_inc_vat',
            'total_amount',
            'coupon_discount_lost',
            'loyalty_discount_lost',
            'vat_amount',
        ];

        foreach ($moneyKeys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = round((float) $row[$key], 2);
            }
        }

        if (isset($row['orders_count'])) {
            $row['orders_count'] = (int) $row['orders_count'];
        }
        if (isset($row['count'])) {
            $row['count'] = (int) $row['count'];
        }

        return $row;
    }
}