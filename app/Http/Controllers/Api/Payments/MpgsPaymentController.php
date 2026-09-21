<?php

namespace App\Http\Controllers\Api\Payments;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Services\MpgsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MpgsPaymentController extends Controller
{
    public function __construct(private readonly MpgsService $mpgs)
    {
    }

    /**
     * POST /api/customer/profile/orders/{order}/pay
     */
    public function initiate(Request $request, Order $order): JsonResponse
    {
        $customer = $request->user('customer');

        abort_unless((int) $order->customer_id === (int) $customer->id, 404);

        try {
            $payment = $this->mpgs->initiate($order);
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
            // الأسهل: افتح اللينك ده (متصفح / WebView) وهو بيفتح صفحة الدفع
            'checkout_url' => $this->mpgs->checkoutUrl($payment),
            // أو لو الفرونت هيستخدم Checkout.js بنفسه
            'session_id'   => $payment->session_id,
            'checkout_js'  => $this->mpgs->checkoutJsUrl(),
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
                $payment = $this->mpgs->sync($payment);
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
     * GET /api/payments/mpgs/checkout/{payment}  (signed)
     * صفحة صغيرة بتحمّل checkout.js وتفتح صفحة الدفع.
     */
    public function checkoutPage(OnlinePayment $payment): Response
    {
        abort_unless(
            $payment->session_id && $payment->status === OnlinePayment::STATUS_INITIATED,
            404
        );

        $config = json_encode([
            'sessionId' => $payment->session_id,
            'returnUrl' => route('payments.mpgs.return', ['payment' => $payment->id]),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $src = e($this->mpgs->checkoutJsUrl());

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>الدفع</title>
<script src="{$src}" data-error="onPayError" data-cancel="onPayCancel"></script>
<script>
var CFG = {$config};
function onPayError()  { location.replace(CFG.returnUrl); }
function onPayCancel() { location.replace(CFG.returnUrl); }
Checkout.configure({ session: { id: CFG.sessionId } });
Checkout.showPaymentPage();
</script>
</head>
<body style="font-family:sans-serif;text-align:center;padding:40px">جاري تحويلك لصفحة الدفع...</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET|POST /api/payments/mpgs/return/{payment}
     * البوابة بترجّع العميل هنا. مبنعتمدش على الـ query، بنتحقق من البوابة مباشرة.
     */
    public function callback(Request $request, OnlinePayment $payment)
    {
        try {
            $payment = $this->mpgs->sync($payment);
        } catch (PaymentException $e) {
            Log::warning('MPGS return sync failed', ['payment_id' => $payment->id]);
        }

        $target = config('services.mpgs.frontend_return_url');

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

    /**
     * POST /api/payments/mpgs/webhook
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.mpgs.webhook_secret');

        if ($secret && ! hash_equals((string) $secret, (string) $request->header('X-Notification-Secret'))) {
            Log::warning('MPGS webhook rejected: bad secret', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $gatewayOrderId = $request->input('order.id');

        if ($gatewayOrderId) {
            $payment = OnlinePayment::where('gateway_order_id', $gatewayOrderId)->first();

            if ($payment) {
                try {
                    // مبنصدّق الـ body، بنجيب الحالة الحقيقية من البوابة
                    $this->mpgs->sync($payment);
                } catch (PaymentException $e) {
                    // 503 عشان البوابة تعيد المحاولة
                    return response()->json(['status' => 'RETRY'], 503);
                }
            } else {
                Log::warning('MPGS webhook for unknown order', ['order_id' => $gatewayOrderId]);
            }
        }

        return response()->json(['status' => 'ACCEPTED']);
    }
}