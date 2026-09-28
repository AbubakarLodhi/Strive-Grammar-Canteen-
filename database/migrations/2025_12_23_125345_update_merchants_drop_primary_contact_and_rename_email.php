<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('merchants', 'primary_contact_number')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS merchants_primary_contact_number_unique');
            } else {
                Schema::table('merchants', function (Blueprint $table): void {
                    $table->dropUnique('merchants_primary_contact_number_unique');
                });
            }
        }

        if (Schema::hasColumn('merchants', 'primary_contact_name')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->dropColumn('primary_contact_name');
            });
        }

        if (Schema::hasColumn('merchants', 'primary_contact_number')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->dropColumn('primary_contact_number');
            });
        }

        if (Schema::hasColumn('merchants', 'primary_contact_email')) {
            Schema::table('merchants', function (Blueprint $table): void {
                $table->renameColumn('primary_contact_email', 'email');
            });
        }
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_number')->nullable()->unique();

            $table->renameColumn('email', 'primary_contact_email');
        });
    }
};
