<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('redeemed_points')->default(0)->after('total_amount');
            $table->decimal('redeemed_amount', 10, 2)->default(0)->after('redeemed_points');
            $table->unsignedInteger('earned_points')->default(0)->after('redeemed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['redeemed_points', 'redeemed_amount', 'earned_points']);
        });
    }
};
