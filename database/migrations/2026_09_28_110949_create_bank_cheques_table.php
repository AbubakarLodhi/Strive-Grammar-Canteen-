<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_cheques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('direction', 20);
            $table->foreignUuid('bank_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('cheque_book_id')->nullable()->constrained('cheque_books')->nullOnDelete()->cascadeOnUpdate();
            $table->string('cheque_number');
            $table->date('cheque_date');
            $table->decimal('amount', 14, 2);
            $table->string('payee_name')->nullable();
            $table->string('payer_name')->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('vendor_id')->nullable()->constrained('vendors')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('counter_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('status', 20)->default('pending');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->foreignUuid('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->foreignUuid('reversal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'bank_account_id', 'cheque_number', 'direction'], 'bank_cheques_number_unique');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'cheque_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_cheques');
    }
};
