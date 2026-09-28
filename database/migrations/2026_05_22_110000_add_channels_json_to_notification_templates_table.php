<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->json('channels')->nullable()->after('events');
        });

        DB::table('notification_templates')
            ->select(['id', 'channel'])
            ->orderBy('id')
            ->each(function (object $row): void {
                if (! filled($row->channel ?? null)) {
                    return;
                }

                DB::table('notification_templates')
                    ->where('id', $row->id)
                    ->update(['channels' => json_encode([$row->channel])]);
            });

        Schema::table('notification_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('notification_templates', 'channel')) {
                try {
                    $table->dropIndex(['channel']);
                } catch (Throwable) {
                    // Index may already be gone or named differently.
                }
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS notification_templates_channel_index');
        }

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->enum('channel', ['email', 'sms', 'whatsapp'])->after('events');
        });

        DB::table('notification_templates')
            ->select(['id', 'channels'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $decoded = json_decode($row->channels ?? '[]', true);
                $first = is_array($decoded) ? ($decoded[0] ?? 'email') : 'email';

                if (! in_array($first, ['email', 'sms', 'whatsapp'], true)) {
                    $first = 'email';
                }

                DB::table('notification_templates')
                    ->where('id', $row->id)
                    ->update(['channel' => $first]);
            });

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('channels');
        });
    }
};
