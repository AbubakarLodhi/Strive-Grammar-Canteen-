<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_daily_slip_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('counter_date');
            $table->unsignedInteger('current_count')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'counter_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_daily_slip_counters');
    }
};
