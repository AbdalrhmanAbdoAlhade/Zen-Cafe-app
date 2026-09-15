<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    /**
     * عرض كل الأوردرات مع فلاتر
     * GET /admin/orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with([
                'branch:id,name_ar,name_en',
                'table:id,table_number',
                'customer:id,name,phone',
                'items.menuItem:id,name_ar,name_en',
                'items.options',
            ]);

        // فلترة بالفرع
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // فلترة بالحالة
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // فلترة بنوع الأوردر
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        // فلترة بتاريخ معين
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        // فلترة من تاريخ
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        // فلترة لتاريخ
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // بحث برقم الأوردر
        if ($request->filled('search')) {
            $query->where('id', 'like', '%' . $request->search . '%');
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($orders);
    }

    /**
     * عرض أوردر واحد
     * GET /admin/orders/{order}
     */
    public function show(Order $order): JsonResponse
    {
        $order->load([
            'branch',
            'table',
            'customer',
            'items.menuItem',
            'items.options.menuOptionValue',
            'payments',
            'statusLogs',
        ]);

        return response()->json(['data' => $order]);
    }

    /**
     * إحصائيات الأوردرات
     * GET /admin/orders/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Order::query();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $stats = [
            'total_orders' => (clone $query)->count(),
            'total_revenue' => (clone $query)->where('status', 'paid')->sum('total_amount'),
            'by_status' => (clone $query)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_type' => (clone $query)
                ->selectRaw('order_type, COUNT(*) as count')
                ->groupBy('order_type')
                ->pluck('count', 'order_type'),
            'today_orders' => (clone $query)->whereDate('created_at', today())->count(),
            'today_revenue' => (clone $query)
                ->whereDate('created_at', today())
                ->where('status', 'paid')
                ->sum('total_amount'),
        ];

        return response()->json(['data' => $stats]);
    }
}