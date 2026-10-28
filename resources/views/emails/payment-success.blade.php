{{-- resources/views/emails/payment-success.blade.php --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payment->gateway_order_id ?? 'تأكيد الدفع' }}</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #F5EFDD; margin: 0; padding: 24px; color: #1B4332; }
        .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 30px rgba(27,67,50,.1); }
        .header { background: linear-gradient(135deg, #1B4332, #2D6A4F); color: #fff; padding: 24px; text-align: center; }
        .header h1 { margin: 0 0 8px; font-size: 20px; }
        .amount { font-size: 28px; font-weight: bold; }
        .body { padding: 24px; }
        .row { margin-bottom: 8px; font-size: 14px; }
        .label { color: #6B7280; }
        .items { margin: 16px 0; border-top: 1px solid #E8E0D0; border-bottom: 1px solid #E8E0D0; padding: 12px 0; }
        .item { margin: 6px 0; font-size: 14px; }
        .total { font-size: 16px; font-weight: bold; margin-top: 12px; }
        .ship { background: #F9F7F1; border-radius: 12px; padding: 14px; margin-top: 16px; font-size: 13px; }
        .footer { text-align: center; padding: 16px; font-size: 12px; color: #9CA3AF; }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <h1>✅ تم تأكيد الدفع</h1>
        <div class="amount">{{ number_format((float) $payment->amount, 2) }} {{ $currency }}</div>
    </div>
    <div class="body">
        <div class="row"><span class="label">رقم الطلب:</span> #{{ $order->id }}</div>
        <div class="row"><span class="label">مرجع الدفع:</span> {{ $payment->gateway_order_id }}</div>
        <div class="row"><span class="label">رقم العملية:</span> {{ $payment->transaction_id ?: $payment->gateway_order_id }}</div>
        <div class="row"><span class="label">تاريخ الدفع:</span>
            {{ optional($payment->paid_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
        </div>
        @if($order->branch)
            <div class="row"><span class="label">الفرع:</span> {{ $order->branch->name_ar ?: $order->branch->name_en }}</div>
        @endif

        <div class="items">
            <strong>تفاصيل الطلب</strong>
            @foreach($order->items as $item)
                @php
                    $name = $item->menuItem?->name_ar
                        ?? $item->menuItem?->name_en
                        ?? $item->productVariant?->product?->name_ar
                        ?? $item->productVariant?->sku
                        ?? 'صنف';
                    $line = (float) $item->unit_price * (int) $item->quantity;
                    if ($item->relationLoaded('options')) {
                        foreach ($item->options as $opt) {
                            $line += (float) $opt->extra_price * (int) $item->quantity;
                        }
                    }
                @endphp
                <div class="item">• {{ $name }} × {{ $item->quantity }} = {{ number_format($line, 2) }} {{ $currency }}</div>
            @endforeach
            <div class="total">الإجمالي: {{ number_format($total, 2) }} {{ $currency }}</div>
        </div>

        @if($order->fulfillment_type === 'shipping' && $order->shipment)
            @php $s = $order->shipment; @endphp
            <div class="ship">
                <strong>بيانات الشحن</strong><br>
                المستلم: {{ $s->recipient_name }}<br>
                الهاتف: {{ $s->recipient_phone }}<br>
                المدينة: {{ $s->city }}<br>
                العنوان: {{ $s->address_line }}
                @if($s->building) - {{ $s->building }}@endif
                @if($s->landmark) - {{ $s->landmark }}@endif
                <br>
                رسوم الشحن: {{ number_format((float) $s->shipping_fee, 2) }} {{ $currency }}
                @if($s->tracking_number)<br>رقم التتبع: {{ $s->tracking_number }}@endif
            </div>
        @endif
    </div>
    <div class="footer">زين كافيه • إشعار تلقائي</div>
</div>
</body>
</html>