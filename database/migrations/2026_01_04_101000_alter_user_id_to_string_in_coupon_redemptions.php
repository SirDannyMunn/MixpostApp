<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter user_id to string (UUID-compatible) without requiring doctrine/dbal
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE coupon_redemptions MODIFY user_id varchar(191)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE coupon_redemptions ALTER COLUMN user_id TYPE varchar(191)');
        } elseif ($driver === 'sqlite') {
            // SQLite cannot alter column types easily; fallback: leave as-is
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE coupon_redemptions MODIFY user_id bigint unsigned');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE coupon_redemptions ALTER COLUMN user_id TYPE bigint');
        } elseif ($driver === 'sqlite') {
            // no-op
        }
    }
};

