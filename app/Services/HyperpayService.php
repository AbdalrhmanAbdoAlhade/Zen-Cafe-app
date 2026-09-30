<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\OnlinePayment;
use App\Models\Order;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class HyperpayService
{
    private const CHECKOUT_LINK_MINUTES = 30;

    /** أكواد HyperPay/OPPWA الرسمية */
    private const SUCCESS   = '/^(000\.000\.|000\.100\.1|000\.[36])/';
    private const PENDING   = '/^(000\.200|800\.400\.5|100\.400\.500)/';
    private const NOT_FOUND = '/^(200\.300\.404|700\.400\.580)/';

    /* ============================================================
     |  إنشاء عملية دفع + checkoutId
     ============================================================ */

    public function initiate(Order $order, array $billing = []): OnlinePayment
    {
        $this->assertConfigured();

        if (! in_array($order->order_type, ['pre_order', 'store'], true)) {
            throw new PaymentException('الدفع الأونلاين متاح لطلبات المنيو الأونلاين والمتجر فقط.');
        }

        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED, Order::STATUS_PAID], true)) {
            throw new PaymentException('مينفعش تدفع الطلب في حالته الحالية.');
        }

        // محاولات سابقة ممكن تكون اتدفعت والعميل لسه ما رجعش
        $order->onlinePayments()
            ->where('status', OnlinePayment::STATUS_INITIATED)
            ->where('created_at', '>=', now()->subHours(2))
            ->get()
            ->each(fn (OnlinePayment $p) => $this->syncQuietly($p));

        if ($order->onlinePayments()->where('status', OnlinePayment::STATUS_PAID)->exists()) {
            throw new PaymentException('الطلب ده مدفوع بالفعل.');
        }

        $amount = round((float) $order->payableAmount(), 2);

        if ($amount <= 0) {
            throw new PaymentException('مفيش مبلغ مستحق على الطلب ده.');
        }

        $payment = OnlinePayment::create([
            'order_id'         => $order->id,
            'customer_id'      => $order->customer_id,
            'gateway'          => 'hyperpay',
            'gateway_order_id' => 'ZC' . $order->id . '-' . Str::upper(Str::random(8)),
            'amount'           => $amount,
            'currency'         => strtoupper((string) config('services.hyperpay.currency', 'SAR')),
            'status'           => OnlinePayment::STATUS_INITIATED,
        ]);

        $params = array_merge([
            'entityId'              => config('services.hyperpay.entity_id'),
            'amount'                => number_format($amount, 2, '.', ''),
            'currency'              => $payment->currency,
            'paymentType'           => 'DB',
            'merchantTransactionId' => $payment->gateway_order_id,
            'merchant.url'          => config('services.hyperpay.merchant_url'),
            'integrity'             => 'true',
        ], $this->billingParams($order, $billing));

        if (config('services.hyperpay.test_mode')) {
            $params['testMode']                         = 'EXTERNAL';
            $params['customParameters[3DS2_enrolled]']  = 'true';
            $params['customParameters[3DS2_flow]']      = 'challenge';
        }

        $response = $this->send(fn () => $this->client()->post($this->url('v1/checkouts'), $params));
        $json     = $response->json() ?? [];
        $code     = (string) ($json['result']['code'] ?? '');

        if (! $response->successful() || empty($json['id']) || $code !== '000.200.100') {
            $payment->update([
                'status'           => OnlinePayment::STATUS_FAILED,
                'gateway_response' => $json,
            ]);

            Log::error('HyperPay create checkout failed', [
                'payment_id' => $payment->id,
                'http'       => $response->status(),
                'body'       => $json,
            ]);

            throw new PaymentException('تعذر بدء عملية الدفع، حاول مرة أخرى.');
        }

        $payment->update([
            'session_id'       => $json['id'],   // checkoutId
            'gateway_response' => $json,         // فيه integrity للـ widget
        ]);

        return $payment;
    }

    public function checkoutUrl(OnlinePayment $payment): string
    {
        return URL::temporarySignedRoute(
            'payments.hyperpay.checkout',
            now()->addMinutes(self::CHECKOUT_LINK_MINUTES),
            ['payment' => $payment->id]
        );
    }

    public function widgetJsUrl(OnlinePayment $payment): string
    {
        return $this->url('v1/paymentWidgets.js') . '?checkoutId=' . urlencode((string) $payment->session_id);
    }

    public function integrity(OnlinePayment $payment): ?string
    {
        return ((array) $payment->gateway_response)['integrity'] ?? null;
    }

    /* ============================================================
     |  التحقق من حالة الدفع (المصدر الوحيد للحقيقة = البوابة)
     ============================================================ */

    public function sync(OnlinePayment $payment): OnlinePayment
    {
        if (in_array($payment->status, [
            OnlinePayment::STATUS_PAID,
            OnlinePayment::STATUS_REFUNDED,
            OnlinePayment::STATUS_REFUND_FAILED,
        ], true)) {
            return $payment;
        }

        // المحاولة فشلت وقت إنشاء الـ checkout، مفيش حاجة على البوابة
        if (! $payment->session_id) {
            return $payment;
        }

        $response = $this->send(fn () => $this->client()->get(
            $this->url("v1/checkouts/{$payment->session_id}/payment"),
            ['entityId' => config('services.hyperpay.entity_id')]
        ));

        $data = $response->json() ?? [];
        $code = (string) ($data['result']['code'] ?? '');

        // العميل لسه ما دفعش
        if ($response->status() === 404 || preg_match(self::NOT_FOUND, $code)) {
            return $payment;
        }

        if (! $response->successful()) {
            Log::error('HyperPay status lookup failed', [
                'payment_id' => $payment->id,
                'http'       => $response->status(),
                'body'       => $data,
            ]);

            throw new PaymentException('تعذر التحقق من حالة الدفع.');
        }

        if (preg_match(self::SUCCESS, $code)) {
            return $this->markPaid($payment, $data);
        }

        if (preg_match(self::PENDING, $code)) {
            return $payment;
        }

        $payment->update([
            'status'           => OnlinePayment::STATUS_FAILED,
            'gateway_response' => array_merge((array) $payment->gateway_response, ['last_result' => $data]),
        ]);

        return $payment->refresh();
    }

    private function markPaid(OnlinePayment $payment, array $data): OnlinePayment
    {
        $amount   = (float) ($data['amount'] ?? 0);
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $merchTx  = (string) ($data['merchantTransactionId'] ?? '');
        $type     = strtoupper((string) ($data['paymentType'] ?? ''));

        // أي اختلاف في المبلغ/العملة/المعرّف/النوع = مش بنعتبرها مدفوعة
        if (
            abs($amount - (float) $payment->amount) > 0.001
            || $currency !== $payment->currency
            || $merchTx !== $payment->gateway_order_id
            || $type !== 'DB'
        ) {
            Log::critical('HyperPay amount/currency/reference mismatch', [
                'payment_id' => $payment->id,
                'expected'   => [(float) $payment->amount, $payment->currency, $payment->gateway_order_id, 'DB'],
                'received'   => [$amount, $currency, $merchTx, $type],
            ]);

            $payment->update([
                'gateway_response' => array_merge((array) $payment->gateway_response, ['mismatch' => $data]),
            ]);

            return $payment->refresh();
        }

        $needsRefund = false;

        DB::transaction(function () use ($payment, $data, &$needsRefund) {
            $locked = OnlinePayment::whereKey($payment->id)->lockForUpdate()->first();

            if (in_array($locked->status, [
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_REFUNDED,
                OnlinePayment::STATUS_REFUND_FAILED,
            ], true)) {
                return; // اتعالجت قبل كده
            }

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->first();

            $duplicate = OnlinePayment::where('order_id', $locked->order_id)
                ->where('status', OnlinePayment::STATUS_PAID)
                ->where('id', '!=', $locked->id)
                ->exists();

            $locked->update([
                'status'           => OnlinePayment::STATUS_PAID,
                'transaction_id'   => $data['id'] ?? null,
                'paid_at'          => now(),
                'gateway_response' => array_merge((array) $locked->gateway_response, ['result' => $data]),
            ]);

            // دفع مرتين، أو الطلب اتلغى/اترفض وهو بيدفع → نرجّع الفلوس
            $needsRefund = $duplicate
                || in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED], true);
        });

        $payment->refresh();

        if ($needsRefund) {
            try {
                $this->refund($payment);
            } catch (PaymentException $e) {
                Log::error('HyperPay auto refund failed', ['payment_id' => $payment->id]);
            }
        }

        return $payment->refresh();
    }

    /* ============================================================
     |  الاسترجاع (RF، ولو فشل يجرّب RV = إلغاء قبل التسوية)
     ============================================================ */

    public function refund(OnlinePayment $payment): OnlinePayment
    {
        if (! in_array($payment->status, [
            OnlinePayment::STATUS_PAID,
            OnlinePayment::STATUS_REFUND_FAILED,
        ], true)) {
            return $payment;
        }

        if (! $payment->transaction_id) {
            throw new PaymentException('مفيش رقم عملية للدفعة دي.');
        }

        $base = ['entityId' => config('services.hyperpay.entity_id')];

        $attempts = [
            $base + [
                'paymentType' => 'RF',
                'amount'      => number_format((float) $payment->amount, 2, '.', ''),
                'currency'    => $payment->currency,
            ],
            $base + ['paymentType' => 'RV'],
        ];

        $log = [];

        foreach ($attempts as $params) {
            $response = $this->send(fn () => $this->client()->post(
                $this->url("v1/payments/{$payment->transaction_id}"),
                $params
            ));

            $json  = $response->json() ?? [];
            $log[] = $json;

            if ($response->successful() && preg_match(self::SUCCESS, (string) ($json['result']['code'] ?? ''))) {
                $payment->update([
                    'status'                => OnlinePayment::STATUS_REFUNDED,
                    'refund_transaction_id' => $json['id'] ?? null,
                    'refunded_at'           => now(),
                    'gateway_response'      => array_merge((array) $payment->gateway_response, ['refund' => $json]),
                ]);

                return $payment;
            }
        }

        $payment->update([
            'status'           => OnlinePayment::STATUS_REFUND_FAILED,
            'gateway_response' => array_merge((array) $payment->gateway_response, ['refund_attempts' => $log]),
        ]);

        Log::error('HyperPay refund failed', ['payment_id' => $payment->id, 'body' => $log]);

        throw new PaymentException('تعذر استرجاع المبلغ من البوابة.');
    }

    /**
     * لو الأوردر مدفوع أونلاين يرجّع الفلوس. مبيرميش exception عشان مايوقفش
     * قبول/رفض/إلغاء الأوردر. لو فشل بيتسجل refund_failed وبيترجع يدوي.
     */
    public function refundIfPaidOnline(Order $order): void
    {
        $payment = $order->onlinePayments()
            ->whereIn('status', [OnlinePayment::STATUS_PAID, OnlinePayment::STATUS_REFUND_FAILED])
            ->latest('id')
            ->first();

        if (! $payment) {
            return;
        }

        try {
            $this->refund($payment);
        } catch (PaymentException $e) {
            Log::error('Refund pending manual review', ['order_id' => $order->id, 'payment_id' => $payment->id]);
        }
    }

    /* ============================================================
     |  Helpers
     ============================================================ */

    private function billingParams(Order $order, array $in): array
    {
        $d        = (array) config('services.hyperpay.billing');
        $customer = $order->customer;
        $parts    = preg_split('/\s+/', trim((string) ($customer?->name ?? '')), 2, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'customer.email'     => $in['email'] ?? $customer?->email ?? $d['email'],
            'customer.givenName' => $in['given_name'] ?? $parts[0] ?? 'Customer',
            'customer.surname'   => $in['surname'] ?? $parts[1] ?? $parts[0] ?? 'Customer',
            'billing.street1'    => $in['street'] ?? $d['street'],
            'billing.city'       => $in['city'] ?? $d['city'],
            'billing.state'      => $in['state'] ?? $d['state'],
            'billing.country'    => strtoupper($in['country'] ?? $d['country']),
            'billing.postcode'   => $in['postcode'] ?? $d['postcode'],
        ];
    }

    private function syncQuietly(OnlinePayment $payment): void
    {
        try {
            $this->sync($payment);
        } catch (PaymentException $e) {
            // مش مشكلة هنا، هنعمل محاولة جديدة
        }
    }

    private function assertConfigured(): void
    {
        foreach (['base_url', 'access_token', 'entity_id'] as $key) {
            if (empty(config("services.hyperpay.$key"))) {
                Log::critical("HyperPay config missing: {$key}");
                throw new PaymentException('بوابة الدفع غير مُعدّة.');
            }
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.hyperpay.access_token'))
            ->acceptJson()
            ->asForm()
            ->timeout(20);
    }

    private function url(string $path): string
    {
        return config('services.hyperpay.base_url') . '/' . ltrim($path, '/');
    }

    private function send(Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::error('HyperPay connection error', ['message' => $e->getMessage()]);
            throw new PaymentException('تعذر الاتصال ببوابة الدفع، حاول مرة أخرى.');
        }
    }
}