<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('bank_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('book_no');
            $table->unsignedInteger('start_number');
            $table->unsignedInteger('end_number');
            $table->unsignedInteger('next_number');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'book_no']);
            $table->index(['merchant_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_books');
    }
};
