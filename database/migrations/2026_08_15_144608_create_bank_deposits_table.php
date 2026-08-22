<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('deposit_no');
            $table->date('deposit_date');
            $table->foreignUuid('bank_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('source_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 14, 2);
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'deposit_no']);
            $table->index(['merchant_id', 'deposit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_deposits');
    }
};
