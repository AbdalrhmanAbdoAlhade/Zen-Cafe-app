<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomMessageJob;
use App\Models\CustomMessageLog;
use App\Models\Customer;
use App\Models\Order;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomMessageController extends Controller
{
    public function __construct(private readonly WhatsAppService $whatsapp) {}

    /**
     * POST /api/admin/messages/send
     *
     * Body:
     * {
     *   "channel": "email" | "whatsapp" | "both",
     *   "body": "نص الرسالة...",
     *   "subject": "عنوان الإيميل (اختياري)",
     *   "customer_id": 12,          // أو
     *   "order_id": 166,            // أو
     *   "email": "x@y.com",         // إرسال مباشر بدون عميل
     *   "phone": "05xxxxxxxx"       // إرسال مباشر بدون عميل
     * }
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel'     => ['required', 'in:email,whatsapp,both'],
            'body'        => ['required', 'string', 'min:1', 'max:5000'],
            'subject'     => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'order_id'    => ['nullable', 'integer', 'exists:orders,id'],
            'email'       => ['nullable', 'email', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:30'],
        ]);

        $customer = null;
        $order    = null;

        if (! empty($data['order_id'])) {
            $order    = Order::with('customer')->findOrFail($data['order_id']);
            $customer = $order->customer;
        } elseif (! empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);
        }

        $email = $data['email']
            ?? $customer?->email
            ?? null;

        $phone = $data['phone']
            ?? $customer?->phone
            ?? null;

        $email = $this->validEmail($email);
        $phone = $this->whatsapp->normalizePhone($phone);

        $channels = match ($data['channel']) {
            'email'    => ['email'],
            'whatsapp' => ['whatsapp'],
            'both'     => ['email', 'whatsapp'],
        };

        $results = [];
        $adminId = $request->user()?->id;

        foreach ($channels as $channel) {
            if ($channel === 'email') {
                if (! $email) {
                    $results[] = [
                        'channel' => 'email',
                        'status'  => 'skipped',
                        'reason'  => 'no_valid_email',
                    ];
                    continue;
                }

                $log = CustomMessageLog::create([
                    'customer_id' => $customer?->id,
                    'order_id'    => $order?->id,
                    'sent_by'     => $adminId,
                    'channel'     => CustomMessageLog::CHANNEL_EMAIL,
                    'to_address'  => $email,
                    'subject'     => $data['subject'] ?? 'رسالة من زين كافيه',
                    'body'        => $data['body'],
                    'status'      => CustomMessageLog::STATUS_PENDING,
                ]);

                SendCustomMessageJob::dispatch($log->id)
                    ->onQueue(config('services.notifications.payment_success_queue', 'notifications'));

                $results[] = [
                    'channel' => 'email',
                    'status'  => 'queued',
                    'log_id'  => $log->id,
                    'to'      => $email,
                ];
            }

            if ($channel === 'whatsapp') {
                if (! $phone) {
                    $results[] = [
                        'channel' => 'whatsapp',
                        'status'  => 'skipped',
                        'reason'  => 'no_valid_phone',
                    ];
                    continue;
                }

                if (! $this->whatsapp->isConfigured()) {
                    $results[] = [
                        'channel' => 'whatsapp',
                        'status'  => 'skipped',
                        'reason'  => 'whatsapp_not_configured',
                    ];
                    continue;
                }

                $log = CustomMessageLog::create([
                    'customer_id' => $customer?->id,
                    'order_id'    => $order?->id,
                    'sent_by'     => $adminId,
                    'channel'     => CustomMessageLog::CHANNEL_WHATSAPP,
                    'to_address'  => $phone,
                    'subject'     => null,
                    'body'        => $data['body'],
                    'status'      => CustomMessageLog::STATUS_PENDING,
                ]);

                SendCustomMessageJob::dispatch($log->id)
                    ->onQueue(config('services.notifications.payment_success_queue', 'notifications'));

                $results[] = [
                    'channel' => 'whatsapp',
                    'status'  => 'queued',
                    'log_id'  => $log->id,
                    'to'      => $this->maskPhone($phone),
                ];
            }
        }

        $queued = collect($results)->where('status', 'queued')->count();

        return response()->json([
            'message' => $queued > 0
                ? "تم جدولة {$queued} رسالة"
                : 'لم يتم جدولة أي رسالة — تحقق من وسائل الاتصال',
            'results' => $results,
        ], $queued > 0 ? 202 : 422);
    }

    /**
     * GET /api/admin/messages
     * سجل الرسائل المخصصة
     */
    public function index(Request $request): JsonResponse
    {
        $q = CustomMessageLog::query()->with(['customer:id,name,phone,email', 'sender:id,name']);

        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('order_id')) {
            $q->where('order_id', $request->integer('order_id'));
        }
        if ($request->filled('channel')) {
            $q->where('channel', $request->string('channel'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        $logs = $q->latest('id')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($logs);
    }

    /**
     * GET /api/admin/messages/{log}
     */
    public function show(CustomMessageLog $message): JsonResponse
    {
        $message->load(['customer:id,name,phone,email', 'order:id,status,total_amount', 'sender:id,name']);

        return response()->json($message);
    }

    /**
     * POST /api/admin/messages/{message}/retry
     * إعادة محاولة رسالة فاشلة
     */
    public function retry(CustomMessageLog $message): JsonResponse
    {
        if ($message->status === CustomMessageLog::STATUS_SENT) {
            return response()->json(['message' => 'الرسالة اترسلت بالفعل'], 422);
        }

        $message->update([
            'status'        => CustomMessageLog::STATUS_PENDING,
            'error_message' => null,
        ]);

        SendCustomMessageJob::dispatch($message->id)
            ->onQueue(config('services.notifications.payment_success_queue', 'notifications'));

        return response()->json([
            'message' => 'تم جدولة إعادة المحاولة',
            'log'     => $message->fresh(),
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

    private function maskPhone(string $phone): string
    {
        return strlen($phone) < 6 ? '***' : substr($phone, 0, 4) . '****' . substr($phone, -3);
    }
}