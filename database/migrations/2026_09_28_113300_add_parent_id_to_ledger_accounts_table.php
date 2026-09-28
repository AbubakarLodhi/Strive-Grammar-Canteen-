<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('ledger_accounts')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
