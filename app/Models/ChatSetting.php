<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSetting extends Model
{
    protected $fillable = [
        'welcome_message_ar',
        'welcome_message_en',
        'offline_message_ar',
        'offline_message_en',
        'handoff_triggers',
        'chat_enabled',
    ];

    protected $casts = [
        'handoff_triggers' => 'array',
        'chat_enabled'     => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'welcome_message_ar' => 'أهلاً بك في زين كافيه 👋 كيف نقدر نساعدك؟',
            'welcome_message_en' => 'Welcome to Zen Cafe 👋 How can we help?',
            'offline_message_ar' => 'حالياً مفيش موظفين متاحين. سيبه رسالتك وهنرد قريب.',
            'handoff_triggers'   => ['موظف', 'خدمة عملاء', 'كلم موظف', 'agent', 'support'],
            'chat_enabled'       => true,
        ]);
    }
}