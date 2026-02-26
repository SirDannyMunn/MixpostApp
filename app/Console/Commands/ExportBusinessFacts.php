<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Models\BusinessFact;

class ExportBusinessFacts extends Command
{
    protected $signature = 'export:business-facts {path=database/seeders/data/business_facts.json : Output JSON path}';
    protected $description = 'Export the current business_facts table to a JSON file.';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0777, true, true);
        }

        $facts = BusinessFact::query()
            ->orderBy('created_at')
            ->get(['id','organization_id','user_id','type','text','confidence','source_knowledge_item_id','created_at'])
            ->map(function ($f) {
                return [
                    'id' => (string) $f->id,
                    'organization_id' => (string) $f->organization_id,
                    'user_id' => (string) $f->user_id,
                    'type' => (string) $f->type,
                    'text' => (string) $f->text,
                    'confidence' => (float) $f->confidence,
                    'source_knowledge_item_id' => $f->source_knowledge_item_id ? (string) $f->source_knowledge_item_id : null,
                    'created_at' => optional($f->created_at)->toDateTimeString(),
                ];
            })
            ->values();

        // Build reference maps to preserve relationships across environments
        $userIds = $facts->pluck('user_id')->unique()->all();
        $orgIds = $facts->pluck('organization_id')->unique()->all();
        $kiIds = $facts->pluck('source_knowledge_item_id')->filter()->unique()->all();

        $userMap = DB::table('users')->whereIn('id', $userIds)->pluck('email', 'id');
        $orgMap = DB::table('organizations')->whereIn('id', $orgIds)->pluck('slug', 'id');
        $kiMap = [];
        if (!empty($kiIds)) {
            $kiRows = DB::table('knowledge_items')
                ->whereIn('id', $kiIds)
                ->get(['id','raw_text_sha256','source','source_id']);
            foreach ($kiRows as $row) {
                $kiMap[$row->id] = [
                    'hash' => $row->raw_text_sha256,
                    'source' => $row->source,
                    'source_id' => $row->source_id,
                ];
            }
        }

        // Enrich rows with link-hints
        $facts = $facts->map(function ($row) use ($userMap, $orgMap, $kiMap) {
            $row['user_email'] = isset($userMap[$row['user_id']]) ? (string) $userMap[$row['user_id']] : null;
            $row['organization_slug'] = isset($orgMap[$row['organization_id']]) ? (string) $orgMap[$row['organization_id']] : null;
            if (!empty($row['source_knowledge_item_id']) && isset($kiMap[$row['source_knowledge_item_id']])) {
                $meta = $kiMap[$row['source_knowledge_item_id']];
                $row['ki_hash'] = $meta['hash'] ?? null;
                $row['ki_source'] = $meta['source'] ?? null;
                $row['ki_source_id'] = $meta['source_id'] ?? null;
            } else {
                $row['ki_hash'] = null;
                $row['ki_source'] = null;
                $row['ki_source_id'] = null;
            }
            return $row;
        })->all();

        File::put($path, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Exported " . count($facts) . " rows to: " . $path);
        return self::SUCCESS;
    }
}
