<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->foreignUuid('vendor_id')
                ->nullable()
                ->after('created_by')
                ->constrained('vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
