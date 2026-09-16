<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // "زمن تجهيز الطلب المسبق" - رقم بالدقايق بيضيفه المدير في وقت الذروة،
            // بيتضاف تلقائي فوق preparation_time_minutes بتاعة الأصناف على كل الطلبات
            // الجديدة (اللي لسه متعملتش)، من غير ما يأثر على الطلبات القايمة أصلاً.
            $table->unsignedInteger('current_prep_offset_minutes')->default(0)->after('is_online_paused');
            $table->timestamp('prep_offset_updated_at')->nullable()->after('current_prep_offset_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['current_prep_offset_minutes', 'prep_offset_updated_at']);
        });
    }
};
