<?php

use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('status')->default(Sale::STATUS_POSTED)->after('payment_type');
            $table->timestamp('posted_at')->nullable()->after('status');
            $table->foreignUuid('posted_by')
                ->nullable()
                ->after('posted_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('status');
        });

        DB::table('sales')->update([
            'status' => Sale::STATUS_POSTED,
            'posted_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posted_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'posted_at']);
        });
    }
};
