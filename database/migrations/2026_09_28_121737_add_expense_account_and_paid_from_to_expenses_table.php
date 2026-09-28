<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignUuid('expense_account_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('ledger_accounts')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreignUuid('paid_from_account_id')
                ->nullable()
                ->after('expense_account_id')
                ->constrained('ledger_accounts')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropConstrainedForeignId('paid_from_account_id');
        });
    }
};
