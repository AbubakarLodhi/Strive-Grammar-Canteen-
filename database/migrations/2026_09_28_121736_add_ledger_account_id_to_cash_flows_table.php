<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_flows', function (Blueprint $table): void {
            $table->foreignUuid('ledger_account_id')
                ->nullable()
                ->after('method')
                ->constrained('ledger_accounts')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('cash_flows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ledger_account_id');
        });
    }
};
