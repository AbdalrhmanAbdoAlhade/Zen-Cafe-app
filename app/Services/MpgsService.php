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

class MpgsService
{
    private const CHECKOUT_LINK_MINUTES = 30;

    /* ============================================================
     |  إنشاء عملية دفع + Session
     ============================================================ */

    public function initiate(Order $order): OnlinePayment
    {
        $this->assertConfigured();

        if (! in_array($order->order_type, ['pre_order', 'store'], true)) {
            throw new PaymentException('الدفع الأونلاين متاح لطلبات المنيو الأونلاين والمتجر فقط.');
        }

        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED, Order::STATUS_PAID], true)) {
            throw new PaymentException('مينفعش تدفع الطلب في حالته الحالية.');
        }

        // محاولات سابقة ممكن تكون اتدفعت والـ webhook لسه ما وصلش
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
            'gateway'          => 'mpgs',
            'gateway_order_id' => 'ZC' . $order->id . '-' . Str::upper(Str::random(8)),
            'amount'           => $amount,
            'currency'         => strtoupper((string) config('services.mpgs.currency', 'SAR')),
            'status'           => OnlinePayment::STATUS_INITIATED,
        ]);

        $response = $this->send(fn () => $this->client()->post($this->url('session'), [
            'apiOperation' => 'INITIATE_CHECKOUT',
            'checkoutMode' => 'WEBSITE',
            'interaction'  => [
                'operation'      => 'PURCHASE',
                'merchant'       => ['name' => config('services.mpgs.merchant_name')],
                'returnUrl'      => route('payments.mpgs.return', ['payment' => $payment->id]),
                'displayControl' => ['billingAddress' => 'HIDE'],
            ],
            'order' => [
                'id'          => $payment->gateway_order_id,
                'amount'      => number_format($amount, 2, '.', ''),
                'currency'    => $payment->currency,
                'description' => 'Zen Cafe order #' . $order->id,
            ],
        ]));

        $json = $response->json() ?? [];

        if (! $response->successful() || empty($json['session']['id'])) {
            $payment->update([
                'status'           => OnlinePayment::STATUS_FAILED,
                'gateway_response' => $json,
            ]);

            Log::error('MPGS create session failed', [
                'payment_id' => $payment->id,
                'http'       => $response->status(),
                'body'       => $json,
            ]);

            throw new PaymentException('تعذر بدء عملية الدفع، حاول مرة أخرى.');
        }

        $payment->update([
            'session_id'        => $json['session']['id'],
            'success_indicator' => $json['successIndicator'] ?? null,
        ]);

        return $payment;
    }

    public function checkoutUrl(OnlinePayment $payment): string
    {
        return URL::temporarySignedRoute(
            'payments.mpgs.checkout',
            now()->addMinutes(self::CHECKOUT_LINK_MINUTES),
            ['payment' => $payment->id]
        );
    }

    public function checkoutJsUrl(): string
    {
        return sprintf(
            '%s/checkout/version/%s/checkout.js',
            config('services.mpgs.gateway_url'),
            config('services.mpgs.version')
        );
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

        $response = $this->send(
            fn () => $this->client()->get($this->url('order/' . $payment->gateway_order_id))
        );

        // 404 = العميل لسه ما فتحش صفحة الدفع، مفيش أوردر على البوابة
        if ($response->status() === 404) {
            return $payment;
        }

        if (! $response->successful()) {
            Log::error('MPGS order lookup failed', [
                'payment_id' => $payment->id,
                'http'       => $response->status(),
                'body'       => $response->json(),
            ]);

            throw new PaymentException('تعذر التحقق من حالة الدفع.');
        }

        $data   = $response->json() ?? [];
        $status = strtoupper((string) ($data['status'] ?? ''));

        if ($status === 'CAPTURED') {
            return $this->markPaid($payment, $data);
        }

        if (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            $payment->update([
                'status'           => OnlinePayment::STATUS_FAILED,
                'gateway_response' => $data,
            ]);
        }

        return $payment->refresh();
    }

    private function markPaid(OnlinePayment $payment, array $data): OnlinePayment
    {
        $captured = (float) ($data['totalCapturedAmount'] ?? 0);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        // مبلغ أو عملة مختلفين عن اللي طلبناه = مش بنعتبرها مدفوعة
        if (abs($captured - (float) $payment->amount) > 0.001 || $currency !== $payment->currency) {
            Log::critical('MPGS amount/currency mismatch', [
                'payment_id' => $payment->id,
                'expected'   => [(float) $payment->amount, $payment->currency],
                'received'   => [$captured, $currency],
            ]);

            $payment->update(['gateway_response' => $data]);

            return $payment->refresh();
        }

        $txn = collect($data['transaction'] ?? [])->first(
            fn ($t) => ($t['transaction']['type'] ?? null) === 'PAYMENT' && ($t['result'] ?? null) === 'SUCCESS'
        );
        $transactionId = $txn['transaction']['id'] ?? null;

        $needsRefund = false;

        DB::transaction(function () use ($payment, $data, $transactionId, &$needsRefund) {
            $locked = OnlinePayment::whereKey($payment->id)->lockForUpdate()->first();

            if (in_array($locked->status, [
                OnlinePayment::STATUS_PAID,
                OnlinePayment::STATUS_REFUNDED,
                OnlinePayment::STATUS_REFUND_FAILED,
            ], true)) {
                return; // اتعالجت قبل كده (الـ webhook والـ return وصلوا مع بعض)
            }

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->first();

            $duplicate = OnlinePayment::where('order_id', $locked->order_id)
                ->where('status', OnlinePayment::STATUS_PAID)
                ->where('id', '!=', $locked->id)
                ->exists();

            $locked->update([
                'status'           => OnlinePayment::STATUS_PAID,
                'transaction_id'   => $transactionId,
                'paid_at'          => now(),
                'gateway_response' => $data,
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
                Log::error('MPGS auto refund failed', ['payment_id' => $payment->id]);
            }
        }

        return $payment->refresh();
    }

    /* ============================================================
     |  الاسترجاع
     ============================================================ */

    public function refund(OnlinePayment $payment): OnlinePayment
    {
        if (! in_array($payment->status, [
            OnlinePayment::STATUS_PAID,
            OnlinePayment::STATUS_REFUND_FAILED,
        ], true)) {
            return $payment;
        }

        $refundTxnId = 'RF-' . now()->format('ymdHis') . Str::upper(Str::random(3));

        $response = $this->send(fn () => $this->client()->put(
            $this->url("order/{$payment->gateway_order_id}/transaction/{$refundTxnId}"),
            [
                'apiOperation' => 'REFUND',
                'transaction'  => [
                    'amount'   => number_format((float) $payment->amount, 2, '.', ''),
                    'currency' => $payment->currency,
                ],
            ]
        ));

        $json = $response->json() ?? [];
        $log  = array_merge((array) $payment->gateway_response, ['refund' => $json]);

        if ($response->successful() && ($json['result'] ?? null) === 'SUCCESS') {
            $payment->update([
                'status'                => OnlinePayment::STATUS_REFUNDED,
                'refund_transaction_id' => $refundTxnId,
                'refunded_at'           => now(),
                'gateway_response'      => $log,
            ]);

            return $payment;
        }

        $payment->update([
            'status'           => OnlinePayment::STATUS_REFUND_FAILED,
            'gateway_response' => $log,
        ]);

        Log::error('MPGS refund failed', [
            'payment_id' => $payment->id,
            'http'       => $response->status(),
            'body'       => $json,
        ]);

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
        foreach (['gateway_url', 'merchant_id', 'api_password'] as $key) {
            if (empty(config("services.mpgs.$key"))) {
                Log::critical("MPGS config missing: {$key}");
                throw new PaymentException('بوابة الدفع غير مُعدّة.');
            }
        }
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(
            'merchant.' . config('services.mpgs.merchant_id'),
            (string) config('services.mpgs.api_password')
        )->acceptJson()->asJson()->timeout(20);
    }

    private function url(string $path): string
    {
        return sprintf(
            '%s/api/rest/version/%s/merchant/%s/%s',
            config('services.mpgs.gateway_url'),
            config('services.mpgs.version'),
            config('services.mpgs.merchant_id'),
            ltrim($path, '/')
        );
    }

    private function send(Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::error('MPGS connection error', ['message' => $e->getMessage()]);
            throw new PaymentException('تعذر الاتصال ببوابة الدفع، حاول مرة أخرى.');
        }
    }
}