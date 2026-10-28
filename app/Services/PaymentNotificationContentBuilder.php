<?php

namespace App\Services;

use App\Models\OnlinePayment;
use App\Models\Order;

class PaymentNotificationContentBuilder
{
    public function build(OnlinePayment $payment, Order $order): array
    {
        $order->loadMissing([
            'customer',
            'branch',
            'items.menuItem',
            'items.productVariant.product',
            'items.options.menuOptionValue',
            'shipment',
        ]);

        $currency = $payment->currency ?? 'SAR';
        $lines    = [];

        $lines[] = '✅ تم تأكيد الدفع بنجاح';
        $lines[] = '';
        $lines[] = 'رقم الطلب: #' . $order->id;
        $lines[] = 'حالة الطلب: ' . $this->statusLabel($order->status);
        $lines[] = 'رقم العملية: ' . ($payment->transaction_id ?: $payment->gateway_order_id);
        $lines[] = 'مرجع الدفع: ' . $payment->gateway_order_id;
        $lines[] = 'المبلغ: ' . number_format((float) $payment->amount, 2) . ' ' . $currency;
        $lines[] = 'تاريخ الدفع: ' . optional($payment->paid_at)
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i');

        if ($order->branch) {
            $lines[] = 'الفرع: ' . ($order->branch->name_ar ?: $order->branch->name_en);
        }

        $lines[] = '';
        $lines[] = 'تفاصيل الطلب:';

        foreach ($order->items as $item) {
            $name = $item->menuItem?->name_ar
                ?? $item->menuItem?->name_en
                ?? $item->productVariant?->product?->name_ar
                ?? $item->productVariant?->product?->name_en
                ?? $item->productVariant?->sku
                ?? 'صنف';

            $qty   = (int) $item->quantity;
            $line  = (float) $item->unit_price * $qty;

            // extras من order_item_options
            $extras = 0.0;
            if ($item->relationLoaded('options')) {
                foreach ($item->options as $opt) {
                    $extras += (float) $opt->extra_price;
                }
            }
            $line += $extras * $qty;

            $lines[] = "• {$name} × {$qty} = " . number_format($line, 2) . " {$currency}";
        }

        $total = (float) ($order->total_inc_vat ?? $order->total_amount ?? $payment->amount);
        $lines[] = '';
        $lines[] = 'الإجمالي: ' . number_format($total, 2) . ' ' . $currency;

        // شحن فقط لو fulfillment = shipping وفيه سجل shipment
        if ($order->fulfillment_type === 'shipping' && $order->shipment) {
            $s = $order->shipment;
            $lines[] = '';
            $lines[] = 'بيانات الشحن:';
            $lines[] = 'المستلم: ' . $s->recipient_name;
            $lines[] = 'الهاتف: ' . $s->recipient_phone;
            $lines[] = 'المدينة: ' . $s->city;
            $addr = trim($s->address_line . ($s->building ? ' - ' . $s->building : '') . ($s->landmark ? ' - ' . $s->landmark : ''));
            $lines[] = 'العنوان: ' . $addr;
            $lines[] = 'رسوم الشحن: ' . number_format((float) $s->shipping_fee, 2) . ' ' . $currency;
            if ($s->tracking_number) {
                $lines[] = 'رقم التتبع: ' . $s->tracking_number;
            }
            $lines[] = 'حالة الشحنة: ' . $this->shipmentStatusLabel($s->status);
        }

        $text = implode("\n", $lines);

        return [
            'subject'  => 'تأكيد الدفع — طلب #' . $order->id,
            'text'     => $text,
            'html'     => view('emails.payment-success', [
                'order'    => $order,
                'payment'  => $payment,
                'currency' => $currency,
                'total'    => $total,
                'lines'    => $lines,
            ])->render(),
            'order'    => $order,
            'payment'  => $payment,
        ];
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending'   => 'قيد الانتظار',
            'accepted'  => 'مقبول',
            'rejected'  => 'مرفوض',
            'preparing' => 'قيد التحضير',
            'ready'     => 'جاهز',
            'served'    => 'تم التقديم',
            'paid'      => 'مدفوع',
            'cancelled' => 'ملغي',
            default     => $status ?? '—',
        };
    }

    private function shipmentStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending'   => 'قيد التجهيز',
            'shipped'   => 'تم الشحن',
            'delivered' => 'تم التسليم',
            default     => $status ?? '—',
        };
    }
}