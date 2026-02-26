<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BusinessFactsDumpSeeder extends Seeder
{
    /**
     * Seed the business_facts table from JSON dump if available.
     */
    public function run(): void
    {
        $path = base_path('database/seeders/data/business_facts.json');
        if (!File::exists($path)) {
            $this->command?->warn('BusinessFactsDumpSeeder: No dump file found. Skipping.');
            return;
        }

        $json = File::get($path);
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            $this->command?->warn('BusinessFactsDumpSeeder: Invalid JSON; skipping.');
            return;
        }

        // Option: control whether to preserve incoming UUIDs
        $preserveIds = filter_var(env('BUSINESS_FACTS_SEED_PRESERVE_IDS', true), FILTER_VALIDATE_BOOL);

        // Default to a clean sync to avoid duplication across runs
        DB::table('business_facts')->truncate();

        $prepared = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            // Ensure required fields exist
            $id = $r['id'] ?? null;
            if (!$preserveIds || empty($id) || !is_string($id) || !preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
                $r['id'] = Str::uuid()->toString();
            }

            // Resolve user by ID or email; skip row if unresolved
            $userId = $r['user_id'] ?? null;
            $user = null;
            if ($userId && DB::table('users')->where('id', $userId)->exists()) {
                $user = $userId;
            } elseif (!empty($r['user_email'])) {
                $user = DB::table('users')->where('email', $r['user_email'])->value('id');
            }
            if (!$user) {
                $this->command?->warn('BusinessFactsDumpSeeder: Skipping row, user not found for email/id: ' . (($r['user_email'] ?? '') ?: ($r['user_id'] ?? '')));
                continue;
            }
            $r['user_id'] = $user;

            // Resolve organization by ID or slug; skip row if unresolved
            $orgId = $r['organization_id'] ?? null;
            $org = null;
            if ($orgId && DB::table('organizations')->where('id', $orgId)->exists()) {
                $org = $orgId;
            } elseif (!empty($r['organization_slug'])) {
                $org = DB::table('organizations')->where('slug', $r['organization_slug'])->value('id');
            }
            if (!$org) {
                $this->command?->warn('BusinessFactsDumpSeeder: Skipping row, organization not found for slug/id: ' . (($r['organization_slug'] ?? '') ?: ($r['organization_id'] ?? '')));
                continue;
            }
            $r['organization_id'] = $org;

            // Resolve source knowledge item by id or linking keys within the same organization
            $ski = $r['source_knowledge_item_id'] ?? null;
            if ($ski && !DB::table('knowledge_items')->where('id', $ski)->exists()) {
                $ski = null;
            }
            if (!$ski) {
                $query = DB::table('knowledge_items')->where('organization_id', $org);
                $usedKey = null;
                if (!empty($r['ki_source']) && !empty($r['ki_source_id'])) {
                    $query2 = clone $query;
                    $query2->where('source', $r['ki_source'])->where('source_id', $r['ki_source_id']);
                    if (!empty($r['ki_hash'])) {
                        $query2->where('raw_text_sha256', $r['ki_hash']);
                    }
                    $ski = $query2->value('id');
                    $usedKey = 'source_pair';
                }
                if (!$ski && !empty($r['ki_hash'])) {
                    $ski = DB::table('knowledge_items')->where('organization_id', $org)->where('raw_text_sha256', $r['ki_hash'])->value('id');
                    $usedKey = $usedKey ?: 'hash';
                }
                if (!$ski && !empty($r['ki_source_id'])) {
                    $ski = DB::table('knowledge_items')->where('organization_id', $org)->where('source_id', $r['ki_source_id'])->value('id');
                    $usedKey = $usedKey ?: 'source_id';
                }
                if (!$ski && !empty($r['ki_source'])) {
                    $ski = DB::table('knowledge_items')->where('organization_id', $org)->where('source', $r['ki_source'])->value('id');
                    $usedKey = $usedKey ?: 'source';
                }
                if ($ski) {
                    $this->command?->line('Resolved KI via ' . $usedKey . ' for fact id ' . $r['id']);
                }
            }
            $r['source_knowledge_item_id'] = $ski ?: null;
            // Ensure required fields exist (ID is handled above; user/org resolved strictly)
            // Null out missing knowledge item links to satisfy FK
            if (!empty($r['source_knowledge_item_id'])) {
                $exists = DB::table('knowledge_items')->where('id', $r['source_knowledge_item_id'])->exists();
                if (!$exists) {
                    $r['source_knowledge_item_id'] = null;
                }
            }
            // Normalize created_at format if present
            if (!empty($r['created_at'])) {
                try {
                    $r['created_at'] = \Illuminate\Support\Carbon::parse($r['created_at'])->toDateTimeString();
                } catch (\Throwable $e) {
                    $r['created_at'] = now()->toDateTimeString();
                }
            } else {
                $r['created_at'] = now()->toDateTimeString();
            }
            // Keep only actual table columns for insert
            $prepared[] = Arr::only($r, [
                'id', 'organization_id', 'user_id', 'type', 'text', 'confidence', 'source_knowledge_item_id', 'created_at'
            ]);
        }

        // Insert in chunks for performance
        foreach (array_chunk($prepared, 1000) as $chunk) {
            DB::table('business_facts')->insert($chunk);
        }

        $this->command?->info('BusinessFactsDumpSeeder: Seeded ' . count($prepared) . ' rows from JSON dump. Preserve IDs: ' . ($preserveIds ? 'yes' : 'no'));
    }
}
