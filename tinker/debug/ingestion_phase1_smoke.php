<?php

use Illuminate\Support\Facades\DB;

echo "Ingestion Phase 1.1 Smoke Test\n";

$byStatus = DB::table('ingestion_sources')
    ->select('status', DB::raw('COUNT(*) as c'))
    ->groupBy('status')
    ->pluck('c', 'status');
echo "ingestion_sources by status: ";
foreach (['pending','processing','completed','failed'] as $s) {
    $n = (int) ($byStatus[$s] ?? 0);
    echo "$s=$n ";
}
echo "\n";

$recent = DB::table('ingestion_sources')
    ->orderByDesc('updated_at')
    ->limit(5)
    ->get(['id','source_type','status','quality_score','updated_at']);
echo "Recent ingestion_sources (top 5):\n";
foreach ($recent as $r) {
    echo " - {$r->id} {$r->source_type} status={$r->status} quality=" . ($r->quality_score ?? 'null') . " updated={$r->updated_at}\n";
}

$kiCount = DB::table('knowledge_items')->count();
$kcCount = DB::table('knowledge_chunks')->count();
$kcEmbedded = 0;
try {
    $row = DB::selectOne("SELECT COUNT(*) as c FROM knowledge_chunks WHERE embedding_vec IS NOT NULL");
    if ($row) { $kcEmbedded = (int) $row->c; }
} catch (Throwable $e) {}
echo "knowledge_items={$kiCount} knowledge_chunks={$kcCount} embedded_chunks={$kcEmbedded}\n";

echo "Done.\n";

