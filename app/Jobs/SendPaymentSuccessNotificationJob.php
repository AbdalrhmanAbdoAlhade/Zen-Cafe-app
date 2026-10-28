<?php

namespace App\Jobs;

use App\Mail\PaymentSuccessMail;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentNotificationLog;
use App\Services\PaymentNotificationContentBuilder;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentSuccessNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(public int $logId) {}

    public function handle(
        WhatsAppService $whatsapp,
        PaymentNotificationContentBuilder $builder
    ): void {
        $log = PaymentNotificationLog::find($this->logId);
        if (! $log || $log->isSent() || $log->isSkipped()) {
            return;
        }

        $payment = OnlinePayment::find($log->online_payment_id);
        $order   = Order::with([
            'customer',
            'branch',
            'items.menuItem',
            'items.productVariant.product',
            'items.options',
            'shipment',
        ])->find($log->order_id);

        if (! $payment || ! $order || $payment->status !== OnlinePayment::STATUS_PAID) {
            $log->update([
                'status'      => PaymentNotificationLog::STATUS_SKIPPED,
                'skip_reason' => 'payment_not_paid_or_missing',
            ]);
            return;
        }

        $content = $builder->build($payment, $order);
        $log->increment('attempts');
        $log->update(['last_attempt_at' => now()]);

        try {
            if ($log->channel === PaymentNotificationLog::CHANNEL_EMAIL) {
                $this->sendEmail($log, $order->customer?->email, $content);
            } else {
                $this->sendWhatsApp($log, $whatsapp, $order->customer?->phone, $content);
            }
        } catch (\Throwable $e) {
            Log::error('Payment notification job failed', [
                'log_id'     => $log->id,
                'payment_id' => $payment->id,
                'order_id'   => $order->id,
                'channel'    => $log->channel,
                'message'    => $e->getMessage(),
            ]);

            $log->update([
                'status'        => PaymentNotificationLog::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e; // لإعادة المحاولة
        }
    }

    private function sendEmail(PaymentNotificationLog $log, ?string $email, array $content): void
    {
        $email = is_string($email) ? trim($email) : null;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $log->update([
                'status'      => PaymentNotificationLog::STATUS_SKIPPED,
                'skip_reason' => 'no_valid_email',
            ]);
            return;
        }

        Mail::to($email)->send(new PaymentSuccessMail(
            subjectLine: $content['subject'],
            htmlBody: $content['html'],
            textBody: $content['text'],
        ));

        $log->update([
            'status'        => PaymentNotificationLog::STATUS_SENT,
            'sent_at'       => now(),
            'error_message' => null,
        ]);
    }

    private function sendWhatsApp(
        PaymentNotificationLog $log,
        WhatsAppService $whatsapp,
        ?string $rawPhone,
        array $content
    ): void {
        $phone = $whatsapp->normalizePhone($rawPhone);
        if (! $phone) {
            $log->update([
                'status'      => PaymentNotificationLog::STATUS_SKIPPED,
                'skip_reason' => 'no_valid_phone',
            ]);
            return;
        }

        $result = $whatsapp->sendText($phone, $content['text']);

        if (! empty($result['success'])) {
            $log->update([
                'status'              => PaymentNotificationLog::STATUS_SENT,
                'provider_message_id' => $result['message_id'] ?? null,
                'sent_at'             => now(),
                'error_message'       => null,
            ]);
            return;
        }

        $log->update([
            'status'        => PaymentNotificationLog::STATUS_FAILED,
            'error_message' => mb_substr((string) ($result['error'] ?? 'unknown'), 0, 1000),
        ]);

        throw new \RuntimeException('WhatsApp send failed: ' . ($result['error'] ?? 'unknown'));
    }
}