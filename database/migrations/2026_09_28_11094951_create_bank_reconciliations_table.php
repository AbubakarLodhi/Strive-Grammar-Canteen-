<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignUuid('bank_account_id')->constrained('ledger_accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('recon_no');
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('statement_opening', 14, 2)->default(0);
            $table->decimal('statement_closing', 14, 2)->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'recon_no']);
            $table->index(['merchant_id', 'bank_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
