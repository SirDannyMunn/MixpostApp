<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE plan_entitlements MODIFY user_id varchar(191)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE plan_entitlements ALTER COLUMN user_id TYPE varchar(191)');
        } elseif ($driver === 'sqlite') {
            // no-op
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE plan_entitlements MODIFY user_id bigint unsigned');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE plan_entitlements ALTER COLUMN user_id TYPE bigint');
        } elseif ($driver === 'sqlite') {
            // no-op
        }
    }
};

