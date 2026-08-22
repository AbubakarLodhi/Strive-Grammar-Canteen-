<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('voucher_no');
            $table->date('voucher_date');
            $table->text('narration')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'voucher_no']);
            $table->index(['merchant_id', 'voucher_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_vouchers');
    }
};
