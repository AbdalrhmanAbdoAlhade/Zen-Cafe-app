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
 * صفحة دفع احترافية بنفس ستايل zensacafe.com
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

    // بيانات الفرع من https://api.zensacafe.com/api/online-menu/1
    $branchNameAr  = 'فرع الرياض الرئيسي';
    $branchAddress = 'شارع الملك فهد، الرياض، المملكة العربية السعودية';
    $logoUrl       = 'https://api.zensacafe.com/storage/branches/V42n4aEcOg0MjNFFmyugfEPuI6SWGlhHgG07Ujc2.png';
    $instagram     = 'https://instagram.com/@riyadh_main';
    $x             = 'https://x.com/riyadh_main';
    $tiktok        = 'https://tiktok.com/@riyadh_main';

    $amount   = number_format((float) $payment->amount, 2);
    $currency = $payment->currency ?? 'SAR';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>إتمام الدفع | زين كافيه</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">

<script src="{$src}" integrity="{$integrity}" crossorigin="anonymous"></script>
<script>
var wpwlOptions = {
    paymentTarget: "_top",
    locale: "ar",
    style: "card",
    brandDetection: true
};
</script>

<style>
    :root {
        --bg: #F5EFDD;
        --card-bg: #FFFFFF;
        --primary: #1B4332;
        --accent: #E07A3D;
        --accent-hover: #C96A2F;
        --text: #1B4332;
        --muted: #6B7280;
        --border: #E8E0D0;
        --shadow: 0 10px 40px -10px rgba(27, 67, 50, 0.12);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Plus Jakarta Sans', 'Amiri', sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        line-height: 1.6;
    }
    .header {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--border);
        padding: 16px 20px;
        position: sticky;
        top: 0;
        z-index: 50;
    }
    .header-inner {
        max-width: 480px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand img {
        width: 48px; height: 48px;
        border-radius: 12px;
        object-fit: cover;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .brand-text h1 { font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 2px; }
    .brand-text p { font-size: 0.75rem; color: var(--muted); }
    .main {
        flex: 1;
        padding: 24px 16px 40px;
        max-width: 480px;
        margin: 0 auto;
        width: 100%;
    }
    .card {
        background: var(--card-bg);
        border-radius: 20px;
        box-shadow: var(--shadow);
        overflow: hidden;
        border: 1px solid var(--border);
    }
    .card-header {
        background: linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%);
        color: white;
        padding: 24px 20px;
        text-align: center;
    }
    .card-header h2 { font-size: 1.25rem; font-weight: 600; margin-bottom: 6px; }
    .card-header .amount { font-size: 2rem; font-weight: 700; letter-spacing: -0.5px; }
    .card-header .currency { font-size: 0.95rem; opacity: 0.9; margin-right: 4px; }
    .card-body { padding: 24px 20px 28px; }
    .secure-badge {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        background: #F0FDF4; color: #166534;
        font-size: 0.8rem; font-weight: 500;
        padding: 10px 14px; border-radius: 12px; margin-bottom: 20px;
    }
    .secure-badge svg { width: 16px; height: 16px; flex-shrink: 0; }
    .payment-form-wrapper { min-height: 220px; }
    .wpwl-form { font-family: 'Plus Jakarta Sans', sans-serif !important; }
    .wpwl-button {
        background: var(--accent) !important;
        border-radius: 12px !important;
        font-weight: 600 !important;
        padding: 14px 24px !important;
        transition: background 0.2s !important;
    }
    .wpwl-button:hover { background: var(--accent-hover) !important; }
    .footer {
        text-align: center;
        padding: 20px 16px 32px;
        max-width: 480px;
        margin: 0 auto;
    }
    .footer-address { font-size: 0.8rem; color: var(--muted); margin-bottom: 16px; }
    .socials { display: flex; justify-content: center; gap: 16px; margin-bottom: 20px; }
    .socials a {
        width: 40px; height: 40px; border-radius: 50%;
        background: white; border: 1px solid var(--border);
        display: flex; align-items: center; justify-content: center;
        color: var(--primary); transition: all 0.2s; text-decoration: none;
    }
    .socials a:hover {
        background: var(--primary); color: white;
        border-color: var(--primary); transform: translateY(-2px);
    }
    .socials svg { width: 18px; height: 18px; }
    .powered { font-size: 0.7rem; color: #9CA3AF; }
    @media (max-width: 400px) {
        .card-header .amount { font-size: 1.75rem; }
    }
</style>
</head>
<body>

<header class="header">
    <div class="header-inner">
        <div class="brand">
            <img src="{$logoUrl}" alt="زين كافيه" onerror="this.style.display='none'">
            <div class="brand-text">
                <h1>{$branchNameAr}</h1>
                <p>Zen Cafe</p>
            </div>
        </div>
    </div>
</header>

<main class="main">
    <div class="card">
        <div class="card-header">
            <h2>إتمام الدفع</h2>
            <div class="amount">
                <span class="currency">{$currency}</span>
                {$amount}
            </div>
        </div>
        <div class="card-body">
            <div class="secure-badge">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                دفع آمن ومشفر بواسطة HyperPay
            </div>
            <div class="payment-form-wrapper">
                <form action="{$return}" class="paymentWidgets" data-brands="VISA MASTER MADA"></form>
            </div>
        </div>
    </div>
</main>

<footer class="footer">
    <p class="footer-address">{$branchAddress}</p>
    <div class="socials">
        <a href="{$instagram}" target="_blank" rel="noopener" title="Instagram" aria-label="Instagram">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
        </a>
        <a href="{$x}" target="_blank" rel="noopener" title="X" aria-label="X">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </a>
        <a href="{$tiktok}" target="_blank" rel="noopener" title="TikTok" aria-label="TikTok">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1v-3.5a6.37 6.37 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.34-6.34V8.76a8.19 8.19 0 004.76 1.52V6.84a4.84 4.84 0 01-1.55-.15z"/></svg>
        </a>
    </div>
    <p class="powered">مدعوم بواسطة HyperPay • زين كافيه</p>
</footer>

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