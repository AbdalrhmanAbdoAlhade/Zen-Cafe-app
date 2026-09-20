<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Services\ChatBotService;
use Illuminate\Http\Request;

class CustomerChatController extends Controller
{
    public function __construct(private ChatBotService $bot) {}

    /** بدء محادثة */
    public function start(Request $request)
    {
        $settings = ChatSetting::current();
        if (! $settings->chat_enabled) {
            return response()->json(['message' => $settings->offline_message_ar], 503);
        }

        $data = $request->validate([
            'phone'      => ['nullable', 'string', 'max:30'],
            'guest_name' => ['nullable', 'string', 'max:100'],
            'branch_id'  => ['nullable', 'integer', 'exists:branches,id'],
            'channel'    => ['nullable', 'in:web,app'],
        ]);

        $customer = $request->user('customer'); // لو Sanctum customer

        $conversation = Conversation::create([
            'customer_id'     => $customer?->id,
            'phone'           => $data['phone'] ?? $customer?->phone,
            'guest_name'      => $data['guest_name'] ?? $customer?->name,
            'branch_id'       => $data['branch_id'] ?? null,
            'channel'         => $data['channel'] ?? 'web',
            'status'          => Conversation::STATUS_BOT,
            'last_message_at' => now(),
        ]);

        $welcome = $settings->welcome_message_ar ?? 'أهلاً بك 👋';

        $botMsg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'body'            => $welcome,
            'metadata'        => ['intent' => 'welcome'],
        ]);

        return response()->json([
            'data' => [
                'conversation' => $conversation,
                'messages'     => [$botMsg],
            ],
        ], 201);
    }

    /** إرسال رسالة */
    public function sendMessage(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if ($conversation->status === Conversation::STATUS_CLOSED) {
            return response()->json(['message' => 'المحادثة مغلقة. ابدأ محادثة جديدة.'], 422);
        }

        $customer = $request->user('customer');

        $customerMsg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_CUSTOMER,
            'sender_id'       => $customer?->id,
            'body'            => $data['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        $botMsg = null;
        if ($conversation->status === Conversation::STATUS_BOT) {
            $botMsg = $this->bot->handleCustomerMessage($conversation->fresh(), $data['body'], $customer);
        }

        return response()->json([
            'data' => [
                'customer_message' => $customerMsg,
                'bot_message'      => $botMsg?->id ? $botMsg : null,
                'conversation'     => $conversation->fresh(),
            ],
        ]);
    }

    public function messages(Conversation $conversation)
    {
        return response()->json([
            'data' => $conversation->messages()->latest('id')->limit(100)->get()->reverse()->values(),
            'conversation' => $conversation,
        ]);
    }

    public function handoff(Conversation $conversation)
    {
        $msg = $this->bot->handoff($conversation->fresh());

        return response()->json([
            'message' => 'تم طلب موظف',
            'data'    => [
                'bot_message'  => $msg,
                'conversation' => $conversation->fresh(),
            ],
        ]);
    }
}