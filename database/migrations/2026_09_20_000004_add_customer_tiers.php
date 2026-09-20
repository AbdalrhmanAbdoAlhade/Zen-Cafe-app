// database/migrations/2026_09_20_000004_add_customer_tiers.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tier', 20)->default('bronze')->after('loyalty_points_balance');
            $table->decimal('total_spent', 12, 2)->default(0)->after('tier');
        });

        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->decimal('tier_silver_min_spent', 12, 2)->default(500)->after('points_expiry_months');
            $table->decimal('tier_gold_min_spent', 12, 2)->default(2000)->after('tier_silver_min_spent');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['tier', 'total_spent']);
        });

        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn(['tier_silver_min_spent', 'tier_gold_min_spent']);
        });
    }
};