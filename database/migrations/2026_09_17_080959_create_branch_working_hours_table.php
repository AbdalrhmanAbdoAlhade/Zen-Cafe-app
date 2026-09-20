<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
      Schema::create('branch_working_hours', function (Blueprint $table) {
          $table->id();
          $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
          $table->unsignedTinyInteger('day_of_week'); // 0=Sunday ... 6=Saturday
          $table->time('opens_at')->nullable();
          $table->time('closes_at')->nullable();
          $table->boolean('is_closed')->default(false);
          $table->timestamps();

          $table->unique(['branch_id', 'day_of_week']);
      });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_working_hours');
    }
};
