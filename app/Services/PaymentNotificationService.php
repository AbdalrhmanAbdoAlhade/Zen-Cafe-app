<?php

namespace App\Services;

use App\Jobs\SendPaymentSuccessNotificationJob;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentNotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly PaymentNotificationContentBuilder $builder,
    ) {}

    /** لا ترمي Exception أبداً */
    public function notifyPaymentSuccess(OnlinePayment $payment): void
    {
        try {
            if ($payment->status !== OnlinePayment::STATUS_PAID) {
                return;
            }

            $order = Order::with([
                'customer',
                'branch',
                'items.menuItem',
                'items.productVariant.product',
                'items.options',
                'shipment',
            ])->find($payment->order_id);

            if (! $order) {
                Log::warning('Payment notify: order missing', [
                    'payment_id' => $payment->id,
                    'order_id'   => $payment->order_id,
                ]);
                return;
            }

            // طلب ملغي/مرفوض → لا إشعار نجاح
            if (in_array($order->status, ['cancelled', 'rejected'], true)) {
                Log::info('Payment notify skipped: order cancelled/rejected', [
                    'payment_id' => $payment->id,
                    'order_id'   => $order->id,
                ]);
                return;
            }

            $customer = $order->customer;
            $email    = $this->validEmail($customer?->email);
            // phone مطلوب في customers — نستخدمه لواتساب
            $phone    = $this->whatsapp->normalizePhone($customer?->phone);

            $channels = $this->decideChannels($email, $phone);

            if ($channels === []) {
                Log::info('Payment notify skipped: no valid contact', [
                    'payment_id'  => $payment->id,
                    'order_id'    => $order->id,
                    'customer_id' => $customer?->id,
                ]);
                return;
            }

            foreach ($channels as $channel) {
                $this->queueChannel($payment, $order, $channel, $email, $phone);
            }
        } catch (\Throwable $e) {
            Log::error('Payment notify dispatcher failed', [
                'payment_id' => $payment->id,
                'order_id'   => $payment->order_id,
                'message'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * إيميل + واتساب صالح → واتساب فقط
     * إيميل فقط → إيميل
     * واتساب فقط → واتساب
     */
    private function decideChannels(?string $email, ?string $phone): array
    {
        $hasEmail = (bool) $email;
        $hasWa    = (bool) $phone && $this->whatsapp->isConfigured();

        if ($hasEmail && $hasWa) {
            return [PaymentNotificationLog::CHANNEL_WHATSAPP];
        }
        if ($hasEmail) {
            return [PaymentNotificationLog::CHANNEL_EMAIL];
        }
        if ($hasWa) {
            return [PaymentNotificationLog::CHANNEL_WHATSAPP];
        }

        return [];
    }

    private function queueChannel(
        OnlinePayment $payment,
        Order $order,
        string $channel,
        ?string $email,
        ?string $phone
    ): void {
        $key = "payment:{$payment->id}:{$channel}:success";

        $existing = PaymentNotificationLog::where('idempotency_key', $key)->first();
        if ($existing && ($existing->isSent() || $existing->isSkipped())) {
            return;
        }

        $log = $existing ?? PaymentNotificationLog::create([
            'online_payment_id' => $payment->id,
            'order_id'          => $order->id,
            'customer_id'       => $order->customer_id,
            'channel'           => $channel,
            'status'            => PaymentNotificationLog::STATUS_PENDING,
            'idempotency_key'   => $key,
            'attempts'          => 0,
        ]);

        if ($channel === PaymentNotificationLog::CHANNEL_EMAIL && ! $email) {
            $log->update(['status' => PaymentNotificationLog::STATUS_SKIPPED, 'skip_reason' => 'no_valid_email']);
            return;
        }

        if ($channel === PaymentNotificationLog::CHANNEL_WHATSAPP && ! $phone) {
            $log->update(['status' => PaymentNotificationLog::STATUS_SKIPPED, 'skip_reason' => 'no_valid_phone']);
            return;
        }

        if ($channel === PaymentNotificationLog::CHANNEL_WHATSAPP && ! $this->whatsapp->isConfigured()) {
            $log->update(['status' => PaymentNotificationLog::STATUS_SKIPPED, 'skip_reason' => 'whatsapp_not_configured']);
            return;
        }

        SendPaymentSuccessNotificationJob::dispatch($log->id)
            ->onQueue(config('services.notifications.payment_success_queue', 'notifications'));
    }

    private function validEmail(?string $email): ?string
    {
        if (! $email) {
            return null;
        }
        $email = trim($email);
        $v = Validator::make(['email' => $email], ['email' => 'required|email:rfc']);

        return $v->fails() ? null : $email;
    }
}