<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Services\HyperpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class HyperpayPaymentController extends Controller
{
    public function __construct(private readonly HyperpayService $hyperpay)
    {
    }

    /**
     * POST /api/customer/profile/orders/{order}/pay
     * كل الحقول اختيارية (بيانات الفوترة): email, given_name, surname, street, city, state, country, postcode
     */
    public function initiate(Request $request, Order $order): JsonResponse
    {
        $customer = $request->user('customer');

        abort_unless((int) $order->customer_id === (int) $customer->id, 404);

        $billing = $request->validate([
            'email'      => ['nullable', 'email', 'max:255'],
            'given_name' => ['nullable', 'string', 'max:100'],
            'surname'    => ['nullable', 'string', 'max:100'],
            'street'     => ['nullable', 'string', 'max:255'],
            'city'       => ['nullable', 'string', 'max:100'],
            'state'      => ['nullable', 'string', 'max:100'],
            'country'    => ['nullable', 'string', 'size:2'],
            'postcode'   => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $payment = $this->hyperpay->initiate($order, $billing);
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment' => [
                'id'               => $payment->id,
                'order_id'         => $order->id,
                'gateway_order_id' => $payment->gateway_order_id,
                'amount'           => (float) $payment->amount,
                'currency'         => $payment->currency,
                'status'           => $payment->status,
            ],
            // الأسهل: افتح اللينك ده (متصفح / WebView) وهو بيعرض فورم الدفع
            'checkout_url'       => $this->hyperpay->checkoutUrl($payment),
            // أو لو الفرونت هيعرض الـ widget بنفسه
            'checkout_id'        => $payment->session_id,
            'integrity'          => $this->hyperpay->integrity($payment),
            'widget_js'          => $this->hyperpay->widgetJsUrl($payment),
            'shopper_result_url' => route('payments.hyperpay.return', ['payment' => $payment->id]),
            'brands'             => 'VISA MASTER MADA',
        ], 201);
    }

    /**
     * GET /api/customer/profile/orders/{order}/payment-status
     * الفرونت يعمل poll عليه بعد الرجوع من صفحة الدفع.
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        $customer = $request->user('customer');

        abort_unless((int) $order->customer_id === (int) $customer->id, 404);

        $payment = $order->onlinePayments()->latest('id')->first();

        if (! $payment) {
            return response()->json(['paid' => false, 'payment' => null]);
        }

        if (in_array($payment->status, [OnlinePayment::STATUS_INITIATED, OnlinePayment::STATUS_FAILED], true)) {
            try {
                $payment = $this->hyperpay->sync($payment);
            } catch (PaymentException $e) {
                // نرجّع آخر حالة معروفة
            }
        }

        return response()->json([
            'paid'    => $order->onlinePayments()->where('status', OnlinePayment::STATUS_PAID)->exists(),
            'payment' => [
                'id'       => $payment->id,
                'status'   => $payment->status,
                'amount'   => (float) $payment->amount,
                'currency' => $payment->currency,
                'paid_at'  => $payment->paid_at,
            ],
        ]);
    }

    /**
     * GET /api/payments/hyperpay/checkout/{payment}  (signed)
     * صفحة صغيرة بتعرض الـ widget بتاع HyperPay.
     */
    public function checkoutPage(OnlinePayment $payment): Response
    {
        abort_unless(
            $payment->session_id && $payment->status === OnlinePayment::STATUS_INITIATED,
            404
        );

        $src       = e($this->hyperpay->widgetJsUrl($payment));
        $integrity = e((string) $this->hyperpay->integrity($payment));
        $return    = e(route('payments.hyperpay.return', ['payment' => $payment->id]));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>الدفع</title>
<script src="{$src}" integrity="{$integrity}" crossorigin="anonymous"></script>
<script>
var wpwlOptions = { paymentTarget: "_top", locale: "ar" };
</script>
</head>
<body style="font-family:sans-serif;max-width:480px;margin:24px auto;padding:0 16px">
<form action="{$return}" class="paymentWidgets" data-brands="VISA MASTER MADA"></form>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET|POST /api/payments/hyperpay/return/{payment}
     * البوابة بترجّع العميل هنا. مبنعتمدش على الـ query (id/resourcePath)،
     * بنسأل البوابة مباشرة بالـ checkoutId المخزّن عندنا.
     */
    public function callback(Request $request, OnlinePayment $payment)
    {
        $returnedId = $request->query('id');

        if ($returnedId && $returnedId !== $payment->session_id) {
            Log::warning('HyperPay return with unexpected checkout id', ['payment_id' => $payment->id]);
        }

        try {
            $payment = $this->hyperpay->sync($payment);
        } catch (PaymentException $e) {
            Log::warning('HyperPay return sync failed', ['payment_id' => $payment->id]);
        }

        $target = config('services.hyperpay.frontend_return_url');

        if ($target) {
            $query = http_build_query([
                'order_id' => $payment->order_id,
                'status'   => $payment->status, // paid | initiated | failed | refunded
            ]);

            return redirect()->away($target . (str_contains($target, '?') ? '&' : '?') . $query);
        }

        return response()->json([
            'order_id' => $payment->order_id,
            'status'   => $payment->status,
            'paid'     => $payment->status === OnlinePayment::STATUS_PAID,
        ]);
    }
}