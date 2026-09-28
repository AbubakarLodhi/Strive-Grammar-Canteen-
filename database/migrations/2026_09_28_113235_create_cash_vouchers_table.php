<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('direction', 20);
            $table->string('voucher_no');
            $table->date('voucher_date');
            $table->foreignUuid('cash_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('counter_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 14, 2);
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'voucher_no']);
            $table->index(['merchant_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_vouchers');
    }
};
