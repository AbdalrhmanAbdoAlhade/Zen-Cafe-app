<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->unique()->after('notes');
            $table->uuid('invoice_uuid')->nullable()->unique()->after('invoice_number');
            $table->timestamp('invoice_issued_at')->nullable()->after('invoice_uuid');
            $table->decimal('subtotal_ex_vat', 12, 2)->nullable()->after('invoice_issued_at');
            $table->decimal('vat_amount', 12, 2)->nullable()->after('subtotal_ex_vat');
            $table->decimal('total_inc_vat', 12, 2)->nullable()->after('vat_amount');
            $table->text('zatca_qr_base64')->nullable()->after('total_inc_vat');
            $table->string('zatca_status', 20)->nullable()->after('zatca_qr_base64');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number',
                'invoice_uuid',
                'invoice_issued_at',
                'subtotal_ex_vat',
                'vat_amount',
                'total_inc_vat',
                'zatca_qr_base64',
                'zatca_status',
            ]);
        });
    }
};