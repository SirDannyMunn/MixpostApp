<?php

use Illuminate\Support\Facades\DB;

// Summary of current AI folders + attachments, plus a small sample of recent ingestion sources
// to help diagnose why inference declined.

$allowedTypes = [
    'fundraiser',
    'launch',
    'case_study',
    'awareness',
    'research_theme',
    'content_series',
    'event',
    'personal_campaign',
];

$platformWords = [
    'instagram','tiktok','twitter','linkedin','youtube','facebook','threads','pinterest','reddit',
];

$aiFolders = DB::select(<<<'SQL'
SELECT
  id::text as id,
    COALESCE(NULLIF(display_name,''), system_name) as name,
  COALESCE(metadata->>'context_type','') as context_type,
  COALESCE(metadata->>'primary_entity','') as primary_entity,
  COALESCE(metadata->>'description','') as description,
    COALESCE(metadata->>'confidence','') as confidence,
  COALESCE(metadata->>'source','') as source,
  created_at
FROM folders
WHERE COALESCE(metadata->>'source','') = 'ai'
ORDER BY created_at DESC
LIMIT 200
SQL);

$aiFolderCount = DB::selectOne("SELECT COUNT(*)::int as c FROM folders WHERE COALESCE(metadata->>'source','')='ai'");
$attachCount = DB::selectOne("SELECT COUNT(*)::int as c FROM ingestion_source_folders");
$aiAttachCount = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int as c
FROM ingestion_source_folders isf
JOIN folders f ON f.id = isf.folder_id
WHERE COALESCE(f.metadata->>'source','')='ai'
SQL);

echo "AI folders (total): {$aiFolderCount->c}\n";
echo "Attachments (total): {$attachCount->c} | AI attachments: {$aiAttachCount->c}\n\n";

$bad = [];
foreach ($aiFolders as $f) {
    $ct = (string) $f->context_type;
    $name = (string) $f->name;

    $lower = mb_strtolower($name);
    $hasPlatform = false;
    foreach ($platformWords as $w) {
        if (str_contains($lower, $w)) { $hasPlatform = true; break; }
    }

    if ($name === '' || !in_array($ct, $allowedTypes, true) || $hasPlatform) {
        $bad[] = $f;
    }
}

echo "Most recent AI folders (up to 25):\n";
foreach (array_slice($aiFolders, 0, 25) as $f) {
    $conf = (string) ($f->confidence ?? '');
    echo "- {$f->id} | {$f->name} | {$f->context_type} | pe={$f->primary_entity} | conf={$conf}\n";
}

echo "\nPotential issues found in AI folders: " . count($bad) . "\n";
foreach (array_slice($bad, 0, 25) as $f) {
    echo "! {$f->id} | {$f->name} | {$f->context_type} | source={$f->source}\n";
}

echo "\nSample ingestion sources (last 25 by created_at):\n";
$sources = DB::select(<<<'SQL'
SELECT
  id::text as id,
  source_type,
  COALESCE(platform,'') as platform,
  COALESCE(title,'') as title,
  COALESCE(raw_url,'') as raw_url,
  COALESCE(origin,'') as origin,
  created_at
FROM ingestion_sources
WHERE deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 25
SQL);

foreach ($sources as $s) {
    echo "- {$s->id} | {$s->source_type} | {$s->platform} | {$s->origin} | " . substr($s->title, 0, 80) . "\n";
}

// If there are sources without any folder attachments, show a few IDs to target for debugging.
$unattached = DB::select(<<<'SQL'
SELECT i.id::text as id, i.source_type, i.created_at
FROM ingestion_sources i
LEFT JOIN ingestion_source_folders isf ON isf.ingestion_source_id=i.id
WHERE i.deleted_at IS NULL
  AND isf.id IS NULL
ORDER BY i.created_at DESC
LIMIT 10
SQL);

echo "\nRecent sources with no folder attachments (up to 10):\n";
foreach ($unattached as $u) {
    echo "- {$u->id} | {$u->source_type} | {$u->created_at}\n";
}

echo "\nLatest AI attachments (up to 25):\n";
$aiAttachments = DB::select(<<<'SQL'
SELECT
    isf.created_at as attached_at,
    isf.ingestion_source_id::text as ingestion_source_id,
    i.source_type,
    COALESCE(i.platform,'') as platform,
    COALESCE(i.origin,'') as origin,
    LEFT(COALESCE(i.title,''), 80) as title,
    isf.folder_id::text as folder_id,
    f.name as folder_name,
    COALESCE(f.metadata->>'context_type','') as context_type,
    COALESCE(f.metadata->>'primary_entity','') as primary_entity,
    COALESCE(f.metadata->>'confidence','') as folder_confidence
FROM ingestion_source_folders isf
JOIN folders f ON f.id = isf.folder_id
JOIN ingestion_sources i ON i.id = isf.ingestion_source_id
WHERE COALESCE(f.metadata->>'source','')='ai'
ORDER BY isf.created_at DESC
LIMIT 25
SQL);

foreach ($aiAttachments as $a) {
        echo "- {$a->attached_at} | src={$a->ingestion_source_id} ({$a->source_type}/{$a->platform}/{$a->origin}) | folder={$a->folder_id} {$a->folder_name} | {$a->context_type} | pe={$a->primary_entity} | conf={$a->folder_confidence}\n";
}
