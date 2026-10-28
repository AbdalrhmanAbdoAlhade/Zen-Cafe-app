<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    /**
     * تقرير مبيعات مفصل (قائمة فواتير)
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'branch:id,name_ar,name_en',
            'customer:id,name,phone,email',
            'items.menuItem:id,name_ar,name_en',
            'items.productVariant',
            'items.options.optionValue',
            'payment',
            'shipment',
        ])->withCount('items');

        // ========================
        //        الفلاتر
        // ========================
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('fulfillment_type')) {
            $query->where('fulfillment_type', $request->fulfillment_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

      // ========================
      //     فلتر التاريخ والوقت
      // ========================

      // سنة
      if ($request->filled('year')) {
          $query->whereYear('created_at', (int) $request->year);
      }

      // شهر
      if ($request->filled('month')) {
          $query->whereMonth('created_at', (int) $request->month);
      }

      // ===== من → إلى (datetime كامل) — from & to =====
      if ($request->filled('from') || $request->filled('to')) {
          if ($request->filled('from')) {
              $query->where('created_at', '>=', \Carbon\Carbon::parse($request->from));
          }
          if ($request->filled('to')) {
              $query->where('created_at', '<=', \Carbon\Carbon::parse($request->to));
          }
      }
      // يوم واحد محدد
      elseif ($request->filled('date')) {
          $date = $request->date;

          $from = $date . ' ' . ($request->from_time ?? '00:00:00');
          $to   = $date . ' ' . ($request->to_time ?? '23:59:59');

          $query->whereBetween('created_at', [
              \Carbon\Carbon::parse($from)->startOfSecond(),
              \Carbon\Carbon::parse($to)->endOfSecond(),
          ]);
      }
      // from_date / to_date
      elseif ($request->filled('from_date') || $request->filled('to_date')) {
          $fromDate = $request->from_date ?? '2000-01-01';
          $toDate   = $request->to_date ?? now()->toDateString();

          $from = $fromDate . ' ' . ($request->from_time ?? '00:00:00');
          $to   = $toDate   . ' ' . ($request->to_time ?? '23:59:59');

          $query->whereBetween('created_at', [
              \Carbon\Carbon::parse($from),
              \Carbon\Carbon::parse($to),
          ]);
      }
      // وقت فقط
      elseif ($request->filled('from_time') || $request->filled('to_time')) {
          $fromTime = $request->from_time ?? '00:00:00';
          $toTime   = $request->to_time ?? '23:59:59';

          $query->whereTime('created_at', '>=', $fromTime)
                ->whereTime('created_at', '<=', $toTime);
      }

        // ========================
        //         السيرش
        // ========================
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('invoice_uuid', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('coupon_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderByDesc('created_at');

        // ========================
        //   ملخص عام (Totals)
        // ========================
        $summary = (clone $query)
            ->toBase()
            ->cloneWithout(['columns', 'orders', 'groups', 'havings', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order'])
            ->selectRaw('
                COUNT(*) as total_orders,
                COALESCE(SUM(subtotal_ex_vat), 0) as total_before_vat,
                COALESCE(SUM(vat_amount), 0) as total_vat,
                COALESCE(SUM(total_inc_vat), 0) as total_after_vat,
                COALESCE(SUM(coupon_discount), 0) as total_coupon_discount,
                COALESCE(SUM(redeemed_amount), 0) as total_redeemed_amount
            ')
            ->first();

        // ========================
        //        Pagination
        // ========================
        $perPage = $request->integer('per_page', 10);
        $orders  = $query->paginate($perPage);

        $data = $orders->getCollection()->map(function (Order $order) {
            return $this->formatOrder($order); // يرجع الأوردر + المنتجات دائماً
        });

        return response()->json([
            'data' => $data,
            'summary' => [
                'total_orders'          => (int) ($summary->total_orders ?? 0),
                'total_before_vat'      => round((float) ($summary->total_before_vat ?? 0), 2),
                'total_vat'             => round((float) ($summary->total_vat ?? 0), 2),
                'total_after_vat'       => round((float) ($summary->total_after_vat ?? 0), 2),
                'total_coupon_discount' => round((float) ($summary->total_coupon_discount ?? 0), 2),
                'total_redeemed_amount' => round((float) ($summary->total_redeemed_amount ?? 0), 2),
            ],
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'from'         => $orders->firstItem(),
                'to'           => $orders->lastItem(),
            ],
        ]);
    }

    /**
     * تفاصيل فاتورة واحدة كاملة
     */
    public function show(Order $order)
    {
        $order->load([
            'branch:id,name_ar,name_en',
            'customer:id,name,phone,email',
            'items.menuItem:id,name_ar,name_en,image',
            'items.productVariant',
            'items.options.optionValue',
            'payment.staff:id,name',
            'shipment',
            'statusLogs.staff:id,name',
        ]);

        return response()->json([
            'data' => $this->formatOrder($order, detailed: true),
        ]);
    }

    /**
     * تنسيق بيانات الفاتورة
     * يرجع دائماً: الأوردر + المنتجات + الأسعار + العميل
     */
    private function formatOrder(Order $order, bool $detailed = false): array
    {
        // ===== المنتجات =====
        $items = $order->items->map(function ($item) {
            $pricing = $item->pricingBreakdown();

            return [
                'id'                 => $item->id,
                'menu_item_id'       => $item->menu_item_id,
                'product_variant_id' => $item->product_variant_id,
                'name_ar'            => $item->menuItem?->name_ar,
                'name_en'            => $item->menuItem?->name_en,
                'variant'            => $item->productVariant ? [
                    'id'   => $item->productVariant->id,
                    'name' => $item->productVariant->name ?? null,
                ] : null,
                'quantity'           => (int) $item->quantity,
                'unit_price'         => $pricing['unit_price'],
                'options_total'      => $pricing['options_total'],
                'vat_percent'        => $pricing['vat'],
                'line_base'          => $pricing['line_base'],      // قبل الضريبة
                'vat_amount'         => $pricing['vat_amount'],     // قيمة الضريبة
                'line_total'         => $pricing['line_total'],     // بعد الضريبة
                'notes'              => $item->notes,
                'options' => $item->options->map(fn ($opt) => [
                    'id'          => $opt->id,
                    'name_ar'     => $opt->optionValue?->name_ar,
                    'name_en'     => $opt->optionValue?->name_en,
                    'extra_price' => round((float) $opt->extra_price, 2),
                ]),
            ];
        })->values();

        $result = [
            'id'                => $order->id,
            'invoice_number'    => $order->invoice_number,
            'invoice_uuid'      => $order->invoice_uuid,
            'invoice_issued_at' => $order->invoice_issued_at?->format('Y-m-d H:i:s'),
            'order_type'        => $order->order_type,
            'fulfillment_type'  => $order->fulfillment_type,
            'fulfillment_label' => $order->fulfillmentLabelAr(),
            'status'            => $order->status,
            'created_at'        => $order->created_at?->format('Y-m-d H:i:s'),
            'received_at'       => $order->received_at?->format('Y-m-d H:i:s'),
            'notes'             => $order->notes,

            // الفرع
            'branch' => [
                'id'      => $order->branch?->id,
                'name_ar' => $order->branch?->name_ar,
                'name_en' => $order->branch?->name_en,
            ],

            // العميل اللي اشترى
            'customer' => [
                'id'    => $order->customer?->id,
                'name'  => $order->customer?->name,
                'phone' => $order->customer?->phone,
                'email' => $order->customer?->email,
            ],

            // المجاميع
            'totals' => [
                'subtotal_ex_vat'  => round((float) $order->subtotal_ex_vat, 2), // قبل الضريبة
                'vat_amount'       => round((float) $order->vat_amount, 2),      // الضريبة
                'total_inc_vat'    => round((float) $order->total_inc_vat, 2),   // بعد الضريبة
                'coupon_code'      => $order->coupon_code,
                'coupon_discount'  => round((float) $order->coupon_discount, 2),
                'redeemed_points'  => (int) $order->redeemed_points,
                'redeemed_amount'  => round((float) $order->redeemed_amount, 2),
                'earned_points'    => (int) $order->earned_points,
                'payable_amount'   => $order->payableAmount(),
            ],

            'items_count' => $order->items_count ?? $order->items->count(),

            // المنتجات دائماً
            'items' => $items,
        ];

        // الدفع
        if ($order->relationLoaded('payment') && $order->payment) {
            $result['payment'] = [
                'method' => $order->payment->method,
                'amount' => round((float) $order->payment->amount, 2),
                'staff'  => $order->payment->staff?->name,
            ];
        }

        // الشحن
        if ($order->relationLoaded('shipment') && $order->shipment) {
            $result['shipment'] = [
                'recipient_name'  => $order->shipment->recipient_name,
                'recipient_phone' => $order->shipment->recipient_phone,
                'city'            => $order->shipment->city,
                'address_line'    => $order->shipment->address_line,
                'shipping_fee'    => round((float) $order->shipment->shipping_fee, 2),
                'status'          => $order->shipment->status,
                'tracking_number' => $order->shipment->tracking_number,
            ];
        }

        // سجل الحالات (في التفاصيل فقط)
        if ($detailed && $order->relationLoaded('statusLogs')) {
            $result['status_logs'] = $order->statusLogs->map(fn ($log) => [
                'status'     => $log->status,
                'staff'      => $log->staff?->name,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);
        }

        return $result;
    }
}