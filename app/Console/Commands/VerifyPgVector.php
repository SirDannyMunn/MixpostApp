<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyPgVector extends Command
{
    protected $signature = 'db:verify:pgvector';

    protected $aliases = [
        'db:verify-pgvector',
    ];
    protected $description = 'Verify PostgreSQL connectivity and pgvector extension setup';

    public function handle(): int
    {
        $this->info('Verifying PostgreSQL + pgvector configuration...');

        // 1) PHP driver check
        $pdoPgsql = extension_loaded('pdo_pgsql');
        $pgsql = extension_loaded('pgsql');
        $this->line(' - PHP extension pdo_pgsql: ' . ($pdoPgsql ? 'OK' : 'MISSING'));
        $this->line(' - PHP extension pgsql: ' . ($pgsql ? 'OK' : 'MISSING'));
        if (!$pdoPgsql) {
            $this->error('Missing pdo_pgsql. Enable it in your PHP configuration before continuing.');
            // Still continue to try to connect; it will likely fail
        }

        try {
            // 2) Basic connection
            $version = DB::selectOne('select version() as v');
            $this->line(' - PostgreSQL version: ' . ($version->v ?? 'unknown'));

            // 3) pgvector extension
            $ext = DB::selectOne("select extversion from pg_extension where extname = 'vector'");
            if ($ext?->extversion) {
                $this->line(' - pgvector extension: OK (version ' . $ext->extversion . ')');
            } else {
                $this->error(' - pgvector extension: NOT FOUND');
            }

            // 4) Vector column presence
            if (Schema::hasTable('knowledge_chunks')) {
                $hasVec = Schema::hasColumn('knowledge_chunks', 'embedding_vec');
                $this->line(' - knowledge_chunks.embedding_vec column: ' . ($hasVec ? 'OK' : 'MISSING'));
            } else {
                $this->line(' - knowledge_chunks table not found (migrations may not be run yet).');
            }

            $this->info('Verification completed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to verify: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
