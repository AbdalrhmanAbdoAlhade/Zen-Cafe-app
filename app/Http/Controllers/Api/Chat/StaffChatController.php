<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Http\Request;

class StaffChatController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', Conversation::STATUS_WAITING_AGENT);

        $items = Conversation::query()
            ->with(['customer:id,name,phone', 'assignedStaff:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return response()->json($items);
    }

    public function accept(Request $request, Conversation $conversation)
    {
        $staff = $request->user('staff'); // حسب الـ guard عندك

        if ($conversation->status === Conversation::STATUS_CLOSED) {
            return response()->json(['message' => 'المحادثة مغلقة'], 422);
        }

        $conversation->update([
            'status'            => Conversation::STATUS_WITH_AGENT,
            'assigned_staff_id' => $staff->id,
        ]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'body'            => "تم توصيلك بالموظف {$staff->name}",
            'metadata'        => ['intent' => 'agent_joined'],
        ]);

        return response()->json([
            'message' => 'تم استلام المحادثة',
            'data'    => $conversation->fresh()->load('messages'),
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation)
    {
        $staff = $request->user('staff');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if ($conversation->status !== Conversation::STATUS_WITH_AGENT) {
            return response()->json(['message' => 'استلم المحادثة أولاً'], 422);
        }

        if ((int) $conversation->assigned_staff_id !== (int) $staff->id) {
            return response()->json(['message' => 'المحادثة معيّنة لموظف آخر'], 403);
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_STAFF,
            'sender_id'       => $staff->id,
            'body'            => $data['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json(['data' => $msg]);
    }

    public function close(Conversation $conversation)
    {
        $conversation->update(['status' => Conversation::STATUS_CLOSED]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'body'            => 'تم إغلاق المحادثة. شكراً لتواصلك معنا.',
            'metadata'        => ['intent' => 'closed'],
        ]);

        return response()->json(['message' => 'تم الإغلاق', 'data' => $conversation->fresh()]);
    }
}