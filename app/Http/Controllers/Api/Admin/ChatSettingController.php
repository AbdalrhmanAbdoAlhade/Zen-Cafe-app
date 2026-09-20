<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSetting;
use Illuminate\Http\Request;

class ChatSettingController extends Controller
{
    public function show()
    {
        return response()->json(['data' => ChatSetting::current()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'welcome_message_ar' => ['nullable', 'string'],
            'welcome_message_en' => ['nullable', 'string'],
            'offline_message_ar' => ['nullable', 'string'],
            'offline_message_en' => ['nullable', 'string'],
            'handoff_triggers'   => ['nullable', 'array'],
            'handoff_triggers.*' => ['string', 'max:50'],
            'chat_enabled'       => ['nullable', 'boolean'],
        ]);

        $settings = ChatSetting::current();
        $settings->update($data);

        return response()->json([
            'message' => 'تم تحديث إعدادات الشات',
            'data'    => $settings->fresh(),
        ]);
    }
}