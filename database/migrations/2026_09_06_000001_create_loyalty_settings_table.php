<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('points_earn_rate', 10, 2)->default(10.00);
            $table->decimal('point_redemption_value', 10, 2)->default(0.50);
            $table->unsignedInteger('minimum_points_to_redeem')->default(20);
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('loyalty_settings')->insert([
            'id' => 1,
            'points_earn_rate' => 10.00,
            'point_redemption_value' => 0.50,
            'minimum_points_to_redeem' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};
