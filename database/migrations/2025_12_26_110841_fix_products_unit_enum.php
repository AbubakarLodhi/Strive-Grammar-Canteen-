<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('
                ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_unit_check
            ');

            DB::statement("
                UPDATE products
                SET unit = 'pcs'
                WHERE unit = 'pieces'
            ");

            DB::statement("
                ALTER TABLE products
                ADD CONSTRAINT products_unit_check
                CHECK (
                    unit IN (
                        'pcs',
                        'liter',
                        'gram',
                        'kg',
                        'job',
                        'hour',
                        'day',
                        'sqm',
                        'set'
                    )
                )
            ");

            return;
        }

        // MySQL / MariaDB: column is an ENUM, not a CHECK constraint.
        DB::table('products')->where('unit', 'pieces')->update(['unit' => 'pcs']);

        DB::statement("
            ALTER TABLE products
            MODIFY COLUMN unit ENUM(
                'pcs',
                'liter',
                'gram',
                'kg',
                'job',
                'hour',
                'day',
                'sqm',
                'set'
            ) NOT NULL DEFAULT 'pcs'
        ");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('
                ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_unit_check
            ');

            DB::statement("
                UPDATE products
                SET unit = 'pieces'
                WHERE unit = 'pcs'
            ");

            DB::statement("
                ALTER TABLE products
                ADD CONSTRAINT products_unit_check
                CHECK (
                    unit IN (
                        'pieces',
                        'liter',
                        'gram',
                        'kg',
                        'job',
                        'hour',
                        'day',
                        'sqm',
                        'set'
                    )
                )
            ");

            return;
        }

        DB::table('products')->where('unit', 'pcs')->update(['unit' => 'pieces']);

        DB::statement("
            ALTER TABLE products
            MODIFY COLUMN unit ENUM(
                'pieces',
                'liter',
                'gram',
                'kg',
                'job',
                'hour',
                'day',
                'sqm',
                'set'
            ) NOT NULL DEFAULT 'pieces'
        ");
    }
};
