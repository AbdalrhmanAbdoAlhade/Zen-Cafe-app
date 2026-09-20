<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\FaqEntry;
use App\Models\Order;
use Illuminate\Support\Str;

class ChatBotService
{
    public function handleCustomerMessage(Conversation $conversation, string $text, ?Customer $customer = null): ChatMessage
    {
        $text = trim($text);

        // لو المحادثة مع موظف → البوت مش بيرد
        if (in_array($conversation->status, [
            Conversation::STATUS_WAITING_AGENT,
            Conversation::STATUS_WITH_AGENT,
        ], true)) {
            return $this->storeBotMessage($conversation, null); // لا رد بوت
        }

        // طلب موظف؟
        if ($this->wantsHandoff($text)) {
            return $this->handoff($conversation);
        }

        $intent = $this->detectIntent($text);

        $reply = match ($intent) {
            'order_status' => $this->replyOrderStatus($text, $conversation, $customer),
            'loyalty'      => $this->replyLoyalty($conversation, $customer),
            'branches'     => $this->replyBranches(),
            'hours'        => $this->replyHours($text),
            'menu'         => $this->replyFromFaq('menu', $text),
            'shipping'     => $this->replyFromFaq('shipping', $text),
            'returns'      => $this->replyFromFaq('returns', $text),
            default        => $this->replyFromFaq($intent, $text) ?? $this->fallbackReply(),
        };

        return $this->storeBotMessage($conversation, $reply, ['intent' => $intent]);
    }

    protected function wantsHandoff(string $text): bool
    {
        $triggers = ChatSetting::current()->handoff_triggers ?? [];
        $lower = mb_strtolower($text);

        foreach ($triggers as $t) {
            if ($t !== '' && Str::contains($lower, mb_strtolower($t))) {
                return true;
            }
        }

        return false;
    }

    protected function detectIntent(string $text): ?string
    {
        $lower = mb_strtolower($text);
        $entries = FaqEntry::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($entries as $entry) {
            foreach ($entry->keywords ?? [] as $kw) {
                if ($kw !== '' && Str::contains($lower, mb_strtolower($kw))) {
                    return $entry->intent_key;
                }
            }
        }

        // heuristics بسيطة
        if (preg_match('/\b(طلب|اوردر|order)\b/ui', $text) || preg_match('/\d{3,}/', $text)) {
            return 'order_status';
        }
        if (preg_match('/نقاط|ولاء|loyalty|tier|رتب/ui', $text)) {
            return 'loyalty';
        }
        if (preg_match('/فرع|فروع|branch/ui', $text)) {
            return 'branches';
        }
        if (preg_match('/ميعاد|مواعيد|ساعات|hours|open/ui', $text)) {
            return 'hours';
        }

        return null;
    }

    protected function replyFromFaq(?string $intent, string $text): ?string
    {
        if (! $intent) {
            return null;
        }

        $entry = FaqEntry::query()
            ->where('intent_key', $intent)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        return $entry?->reply_ar;
    }

    protected function replyOrderStatus(string $text, Conversation $conversation, ?Customer $customer): string
    {
        // رقم طلب في الرسالة
        if (preg_match('/\b(\d{1,10})\b/', $text, $m)) {
            $order = Order::query()->whereKey((int) $m[1])->first();
            if ($order) {
                return $this->formatOrderStatus($order);
            }
        }

        // آخر طلب للعميل المسجل
        if ($customer) {
            $order = Order::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->first();
            if ($order) {
                return $this->formatOrderStatus($order);
            }
        }

        // بحث بالهاتف على المحادثة
        if ($conversation->phone) {
            $order = Order::query()
                ->whereHas('customer', fn ($q) => $q->where('phone', $conversation->phone))
                ->latest('id')
                ->first();
            if ($order) {
                return $this->formatOrderStatus($order);
            }
        }

        return "ابعت رقم الطلب (مثال: 1234) أو سجّل دخول بحسابك عشان أقدر أجيب حالة آخر طلب.";
    }

    protected function formatOrderStatus(Order $order): string
    {
        $map = [
            'pending'    => 'قيد الانتظار',
            'accepted'   => 'تم القبول',
            'preparing'  => 'قيد التحضير',
            'ready'      => 'جاهز',
            'served'     => 'تم التقديم',
            'paid'       => 'مدفوع',
            'rejected'   => 'مرفوض',
            'cancelled'  => 'ملغي',
        ];

        $statusAr = $map[$order->status] ?? $order->status;

        return "طلب رقم #{$order->id}\nالحالة: {$statusAr}\nالمبلغ: {$order->total_amount}";
    }

    protected function replyLoyalty(Conversation $conversation, ?Customer $customer): string
    {
        if (! $customer && $conversation->customer_id) {
            $customer = Customer::find($conversation->customer_id);
        }

        if (! $customer) {
            return 'عشان أعرض رصيد النقاط والرتبة، سجّل دخول أو ابعت رقم الهاتف المرتبط بحسابك.';
        }

        $tierMap = [
            'bronze' => 'برونزي',
            'silver' => 'فضي',
            'gold'   => 'ذهبي',
        ];

        $tier = $tierMap[$customer->tier ?? 'bronze'] ?? ($customer->tier ?? 'برونزي');
        $points = (int) ($customer->loyalty_points_balance ?? 0);
        $spent = $customer->total_spent ?? 0;

        return "رصيد نقاطك: {$points}\nرتبتك: {$tier}\nإجمالي مشترياتك: {$spent}";
    }

    protected function replyBranches(): string
    {
        $branches = Branch::query()->active()->get(['id', 'name_ar', 'name_en', 'address_ar', 'address_en']);

        if ($branches->isEmpty()) {
            return 'حالياً مفيش فروع معروضة.';
        }

        $lines = $branches->map(function (Branch $b) {
            $open = method_exists($b, 'isOpenNow') && $b->isOpenNow() ? 'مفتوح الآن' : 'راجع المواعيد';
            return "• {$b->name_ar} — {$open}";
        });

        return "فروعنا:\n" . $lines->implode("\n");
    }

    protected function replyHours(string $text): string
    {
        $branch = Branch::query()->active()->first();

        if (! $branch) {
            return $this->replyFromFaq('hours', $text) ?? 'مواعيد العمل تختلف حسب الفرع.';
        }

        if (method_exists($branch, 'workingHours')) {
            $hours = $branch->workingHours;
            if ($hours->isNotEmpty()) {
                $days = [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];
                $lines = $hours->map(function ($h) use ($days) {
                    $name = $days[$h->day_of_week] ?? $h->day_of_week;
                    if ($h->is_closed) {
                        return "{$name}: مغلق";
                    }
                    $open = $h->opens_at ? substr((string) $h->opens_at, 0, 5) : '--';
                    $close = $h->closes_at ? substr((string) $h->closes_at, 0, 5) : '--';
                    return "{$name}: {$open} – {$close}";
                });
                return "مواعيد {$branch->name_ar}:\n" . $lines->implode("\n");
            }
        }

        return $this->replyFromFaq('hours', $text) ?? 'مواعيد العمل تختلف حسب الفرع. حدد الفرع لو حابب تفاصيل أدق.';
    }

    protected function fallbackReply(): string
    {
        return "مش فاهم طلبك كويس 🙏\nتقدر تسأل عن: الفروع، المواعيد، المنيو، حالة الطلب، النقاط، الشحن، أو الإرجاع.\nأو اكتب «موظف» عشان نححوّلك لخدمة العملاء.";
    }

    public function handoff(Conversation $conversation): ChatMessage
    {
        $conversation->update([
            'status' => Conversation::STATUS_WAITING_AGENT,
        ]);

        // هنا تقدر تبعت Event للموظفين
        // event(new ConversationWaitingAgent($conversation));

        return $this->storeBotMessage(
            $conversation,
            'تم تحويلك لموظف خدمة العملاء. انتظر لحظة من فضلك...',
            ['intent' => 'handoff']
        );
    }

    protected function storeBotMessage(Conversation $conversation, ?string $body, array $meta = []): ChatMessage
    {
        if ($body === null) {
            // dummy no-op message object لن نستخدمه — في الممارسة Controllers يتخطوا الرد
            return new ChatMessage();
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'sender_id'       => null,
            'body'            => $body,
            'metadata'        => $meta,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $msg;
    }
}