<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('journal_voucher_line_id')->constrained('journal_voucher_lines')->restrictOnDelete()->cascadeOnUpdate();
            $table->boolean('is_matched')->default(false);
            $table->string('statement_ref')->nullable();
            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'journal_voucher_line_id'], 'bank_recon_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
    }
};
