<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentSuccessNotificationJob;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentNotificationLog;
use App\Services\PaymentNotificationService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentNotificationController extends Controller
{
    public function __construct(
        private readonly PaymentNotificationService $notifier,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * GET /api/admin/payments/{payment}/notifications
     * حالة إرسال الإيميل/واتساب لعملية دفع معيّنة
     */
    public function index(OnlinePayment $payment): JsonResponse
    {
        $logs = PaymentNotificationLog::where('online_payment_id', $payment->id)
            ->orderBy('id')
            ->get([
                'id',
                'channel',
                'status',
                'skip_reason',
                'error_message',
                'provider_message_id',
                'attempts',
                'sent_at',
                'last_attempt_at',
                'created_at',
            ]);

        return response()->json([
            'payment_id' => $payment->id,
            'order_id'   => $payment->order_id,
            'status'     => $payment->status,
            'logs'       => $logs,
        ]);
    }

    /**
     * POST /api/admin/payments/{payment}/notifications/resend
     * body اختياري: { "channel": "email" } أو { "channel": "whatsapp" }
     * لو مش محدد → يعيد بناء القنوات حسب قواعد النظام
     */
    public function resend(Request $request, OnlinePayment $payment): JsonResponse
    {
        if ($payment->status !== OnlinePayment::STATUS_PAID) {
            return response()->json([
                'message' => 'الإشعار متاح فقط للمدفوعات الناجحة (paid).',
                'status'  => $payment->status,
            ], 422);
        }

        $channel = $request->input('channel'); // email | whatsapp | null

        if ($channel && ! in_array($channel, [
            PaymentNotificationLog::CHANNEL_EMAIL,
            PaymentNotificationLog::CHANNEL_WHATSAPP,
        ], true)) {
            return response()->json(['message' => 'channel يجب أن يكون email أو whatsapp'], 422);
        }

        $order = Order::with('customer')->find($payment->order_id);
        if (! $order) {
            return response()->json(['message' => 'الطلب غير موجود'], 404);
        }

        // إعادة إرسال قناة واحدة فقط
        if ($channel) {
            return $this->resendSingleChannel($payment, $order, $channel);
        }

        // إعادة بناء حسب القواعد (واتساب له الأولوية لو الاتنين موجودين)
        try {
            $this->notifier->notifyPaymentSuccess($payment->refresh());
        } catch (\Throwable $e) {
            Log::error('Admin resend notify failed', [
                'payment_id' => $payment->id,
                'message'    => $e->getMessage(),
            ]);

            return response()->json(['message' => 'فشل جدولة الإشعار', 'error' => $e->getMessage()], 500);
        }

        $logs = PaymentNotificationLog::where('online_payment_id', $payment->id)->get();

        return response()->json([
            'message' => 'تم جدولة الإشعار حسب وسائل الاتصال المتاحة',
            'logs'    => $logs,
        ]);
    }

    /**
     * POST /api/admin/orders/{order}/notifications/resend
     * نفس الفكرة من رقم الطلب
     */
    public function resendByOrder(Request $request, Order $order): JsonResponse
    {
        $payment = OnlinePayment::where('order_id', $order->id)
            ->where('status', OnlinePayment::STATUS_PAID)
            ->latest('id')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'لا توجد عملية دفع ناجحة لهذا الطلب'], 404);
        }

        return $this->resend($request, $payment);
    }

    private function resendSingleChannel(
        OnlinePayment $payment,
        Order $order,
        string $channel
    ): JsonResponse {
        $customer = $order->customer;
        $email    = $this->validEmail($customer?->email);
        $phone    = $this->whatsapp->normalizePhone($customer?->phone);

        if ($channel === PaymentNotificationLog::CHANNEL_EMAIL && ! $email) {
            return response()->json(['message' => 'لا يوجد بريد إلكتروني صالح للعميل'], 422);
        }

        if ($channel === PaymentNotificationLog::CHANNEL_WHATSAPP) {
            if (! $phone) {
                return response()->json(['message' => 'لا يوجد رقم هاتف صالح للعميل'], 422);
            }
            if (! $this->whatsapp->isConfigured()) {
                return response()->json(['message' => 'واتساب غير مفعّل في الإعدادات'], 422);
            }
        }

        $key = "payment:{$payment->id}:{$channel}:success";

        // نسمح بإعادة المحاولة حتى لو اتبعت قبل كده (force resend إداري)
        $log = PaymentNotificationLog::firstOrNew(['idempotency_key' => $key]);
        $log->fill([
            'online_payment_id' => $payment->id,
            'order_id'          => $order->id,
            'customer_id'       => $order->customer_id,
            'channel'           => $channel,
            'status'            => PaymentNotificationLog::STATUS_PENDING,
            'skip_reason'       => null,
            'error_message'     => null,
            'provider_message_id' => null,
            'sent_at'           => null,
        ]);
        // لو كان sent قبل كده، نغيّر المفتاح عشان نسمح بإعادة إرسال إدارية
        if ($log->exists && $log->getOriginal('status') === PaymentNotificationLog::STATUS_SENT) {
            $log->idempotency_key = $key . ':admin:' . now()->timestamp;
            $log->exists = false; // create جديد
        }
        $log->save();

        SendPaymentSuccessNotificationJob::dispatch($log->id)
            ->onQueue(config('services.notifications.payment_success_queue', 'notifications'));

        return response()->json([
            'message' => "تم جدولة إعادة إرسال {$channel}",
            'log'     => $log->fresh(),
        ]);
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