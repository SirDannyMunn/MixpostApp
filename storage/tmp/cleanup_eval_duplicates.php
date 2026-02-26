<?php
use Illuminate\Support\Facades\DB;

$orgId = '019b31e7-8ff9-73e4-ac74-f9b72214bc31';
$path = base_path('docs/fixtures/ingestion/factual_short.txt');
$raw = is_file($path) ? file_get_contents($path) : '';
$norm = preg_replace('/\s+/u', ' ', trim((string) $raw)) ?? trim((string) $raw);
$hash = hash('sha256', $norm);

echo "Normalized SHA256: $hash\n";

$ids = DB::table('knowledge_items')
    ->where('organization_id', $orgId)
    ->where('raw_text_sha256', $hash)
    ->pluck('id')
    ->all();

echo 'Found items: ' . count($ids) . "\n";
if (!empty($ids)) {
    DB::table('knowledge_chunks')->whereIn('knowledge_item_id', $ids)->delete();
    DB::table('knowledge_items')->whereIn('id', $ids)->delete();
    echo "Deleted duplicates (items+chunks).\n";
}
