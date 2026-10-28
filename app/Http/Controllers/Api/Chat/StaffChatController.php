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
    $user = $request->user();

    $status   = $request->query('status');
    $branchId = $request->query('branch_id');
    $search   = $request->query('search');

    $query = Conversation::query()
        ->with([
            'customer:id,name,phone',
            'assignedStaff:id,name',
            'branch:id,name_ar',
        ])
        ->when($status, fn ($q) => $q->where('status', $status))
        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
        ->when($search, function ($q) use ($search) {
            $q->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($c) use ($search) {
                      $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        })
        ->orderByDesc('last_message_at');

    // ← هنا
    if ($user instanceof \App\Models\Staff && $user->role === 'manager' && $user->branch_id) {
        $query->where(function ($q) use ($user) {
            $q->where('branch_id', $user->branch_id)
              ->orWhereNull('branch_id');
        });
    }

    $items = $query->paginate(20);

    return response()->json($items);
}
    public function show(Conversation $conversation)
    {
        $conversation->load([
            'customer:id,name,phone,tier,loyalty_points_balance',
            'assignedStaff:id,name',
            'branch:id,name_ar',
            'messages' => fn ($q) => $q->orderBy('id'),
        ]);

        return response()->json([
            'data' => $conversation,
        ]);
    }

    public function accept(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        if ($conversation->status === Conversation::STATUS_CLOSED) {
            return response()->json(['message' => 'المحادثة مغلقة'], 422);
        }

        // لو staff → نسجل الـ assigned_staff_id
        // لو admin → ممكن نخليها null أو نسجلها بطريقة مختلفة
        $assignedStaffId = null;
        $name = 'موظف';

        if ($user instanceof \App\Models\Staff) {
            $assignedStaffId = $user->id;
            $name = $user->name;
        } elseif ($user instanceof \App\Models\User) {
            // Admin
            $name = $user->name ?? 'أدمن';
            // لو حابب تسجل الـ admin كمان، تقدر تضيف عمود assigned_admin_id
        }

        $conversation->update([
            'status'            => Conversation::STATUS_WITH_AGENT,
            'assigned_staff_id' => $assignedStaffId,
        ]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'body'            => "تم توصيلك بـ {$name}",
            'metadata'        => ['intent' => 'agent_joined'],
        ]);

        return response()->json([
            'message' => 'تم استلام المحادثة',
            'data'    => $conversation->fresh()->load('messages'),
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // نسمح بالرد طالما المحادثة مش مقفولة
        if ($conversation->status === Conversation::STATUS_CLOSED) {
            return response()->json(['message' => 'المحادثة مغلقة'], 422);
        }

        // لو المحادثة لسه bot أو waiting → نحولها لـ with_agent
        if (in_array($conversation->status, [
            Conversation::STATUS_BOT,
            Conversation::STATUS_WAITING_AGENT,
        ])) {
            $conversation->update([
                'status' => Conversation::STATUS_WITH_AGENT,
            ]);
        }

        // تحديد نوع المرسل
        if ($user instanceof \App\Models\Staff) {
            $senderType = ChatMessage::SENDER_STAFF;
            $senderId   = $user->id;
        } else {
            // Admin / Manager (User)
            $senderType = ChatMessage::SENDER_STAFF; // أو تعمل SENDER_ADMIN لو حابب
            $senderId   = $user->id;
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => $senderType,
            'sender_id'       => $senderId,
            'body'            => $data['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json(['data' => $msg]);
    }

    public function close(Request $request, Conversation $conversation)
    {
        $conversation->update(['status' => Conversation::STATUS_CLOSED]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => ChatMessage::SENDER_BOT,
            'body'            => 'تم إغلاق المحادثة. شكراً لتواصلك معنا.',
            'metadata'        => ['intent' => 'closed'],
        ]);

        return response()->json([
            'message' => 'تم الإغلاق',
            'data'    => $conversation->fresh(),
        ]);
    }
}