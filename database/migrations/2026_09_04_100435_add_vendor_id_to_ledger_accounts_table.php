<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->foreignUuid('vendor_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('vendors')
                ->nullOnDelete();

            $table->unique(['merchant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'vendor_id']);
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
