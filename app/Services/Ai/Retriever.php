<?php

namespace App\Services\Ai;

use App\Models\BusinessFact;
use App\Models\KnowledgeChunk;
use App\Models\SwipeStructure;
use App\Services\Ai\EmbeddingsService;
use App\Services\Ai\SwipeStructures\EphemeralStructureGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Ingestion\KnowledgeCompiler;

class Retriever
{
    public function __construct(
        protected EphemeralStructureGenerator $ephemeralStructureGenerator,
    ) {}

    public function knowledgeChunks(string $organizationId, string $userId, string $query, string $intent, int $limit = 5, array $filters = []): array
    {
        $query = trim((string) $query);
        $filters = is_array($filters) ? $filters : [];

        // Load role policy from config (can be overridden in filters)
        $vectorRoles = (array) ($filters['vectorRoles'] ?? config('ai_chunk_roles.vector_searchable_roles', []));
        $minTokenCount = (int) ($filters['minTokenCount'] ?? config('ai_chunk_roles.min_token_count', 12));
        $minCharCount = (int) ($filters['minCharCount'] ?? config('ai_chunk_roles.min_char_count', 60));
        $roleBoosts = (array) config('ai_chunk_roles.role_boosts', []);

        // Step 1: intent classification (intent + domain + funnel stage). Fall back to provided intent.
        $intentInfo = $this->classifyIntentDomainFunnel($query, $intent);
        $intent = (string) ($intentInfo['intent'] ?? $intent);
        $retrievalDomain = (string) ($intentInfo['domain'] ?? '');

        // Step 2: query expansion for retrieval (intent-aware, not prompt-literal)
        $expandedTerms = $this->expandRetrievalQuery($query, $intentInfo);
        $embedQuery = trim(implode("\n", $expandedTerms));
        if ($embedQuery === '') {
            $embedQuery = $query;
        }
        // Normalize filter inputs
        $filterKiId = (string) ($filters['knowledge_item_id'] ?? '');
        $filterKiIds = array_values(array_filter(array_map('strval', (array) ($filters['knowledge_item_ids'] ?? [])), fn($v) => $v !== ''));
        if ($filterKiId !== '' && empty($filterKiIds)) { $filterKiIds = [$filterKiId]; }

        $filterFolderId = (string) ($filters['folder_id'] ?? '');
        $filterFolderIds = array_values(array_filter(array_map('strval', (array) ($filters['folder_ids'] ?? [])), fn($v) => $v !== ''));
        if ($filterFolderId !== '' && empty($filterFolderIds)) { $filterFolderIds = [$filterFolderId]; }
        // Per-intent caps (configurable)
        $intentCaps = (array) config('vector.retrieval.max_per_intent', [
            'educational' => 5,
            'persuasive' => 4,
            'story' => 6,
            'contrarian' => 5,
            'emotional' => 5,
            '*' => 5,
        ]);
        $cap = (int) ($intentCaps[$intent] ?? $intentCaps['*'] ?? 5);
        $limit = max(1, min($limit, $cap, 20));
        // Ranking-first retrieval configuration
        $topN = (int) (config('ai.retriever.top_n', 20));
        $softScoreLimit = (float) (config('ai.retriever.soft_score_limit', 0.90));
        $weights = (array) (config('ai.retriever.weights', [
            'distance' => 0.6,
            'authority' => 0.15,
            'confidence' => 0.15,
            'time_horizon' => 0.10,
        ]));
        $nearMatchDistance = (float) (config('ai.retriever.near_match_distance', 0.10));

        // If no query, return recent chunks as a safe default (normalized-only, role-filtered)
        if ($query === '') {
            $qb = KnowledgeChunk::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('source_variant', 'normalized')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('usage_policy')
                        ->orWhere('usage_policy', '!=', 'never_generate');
                });
            
            // Apply role filtering
            if (!empty($vectorRoles)) {
                $qb->whereIn('chunk_role', $vectorRoles);
            }
            // Apply minimum thresholds
            if ($minTokenCount > 0) {
                $qb->where('token_count', '>=', $minTokenCount);
            }
            if ($minCharCount > 0) {
                $qb->whereRaw('CHAR_LENGTH(chunk_text) >= ?', [$minCharCount]);
            }
            
            if (!empty($filterKiIds)) { $qb->whereIn('knowledge_item_id', $filterKiIds); }

            // Folder scoping: limit to chunks whose knowledge_item's ingestion source is attached to one of the folders.
            if (!empty($filterFolderIds) && Schema::hasTable('ingestion_source_folders')) {
                $qb->whereIn('knowledge_item_id', function ($q) use ($filterFolderIds) {
                    $q->select('ki.id')
                        ->distinct()
                        ->from('knowledge_items as ki')
                        ->join('ingestion_source_folders as isf', 'isf.ingestion_source_id', '=', 'ki.ingestion_source_id')
                        ->whereIn('isf.folder_id', $filterFolderIds);
                });
            }

            return $qb->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id','knowledge_item_id','chunk_text','chunk_type','chunk_role','chunk_kind','usage_policy','authority','confidence','time_horizon','token_count','domain','actor','tags'])
                ->toArray();
        }

        // Try semantic search via pgvector
        try {
            $embed = app(EmbeddingsService::class)->embedOne($embedQuery);
            if (!empty($embed)) {
                $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float)$f), '0'), $embed)) . ']';
                // Perform vector search using cosine distance operator, join to items for confidence
                $whereExtra = '';
                $params = [];
                if (!empty($filterKiIds)) {
                    $placeholders = implode(',', array_fill(0, count($filterKiIds), '?'));
                    $whereExtra = " AND kc.knowledge_item_id IN ($placeholders) ";
                    $params = array_merge($params, $filterKiIds);
                }

                // Folder scoping: use EXISTS to avoid duplicate rows when a source is in multiple folders.
                if (!empty($filterFolderIds) && Schema::hasTable('ingestion_source_folders')) {
                    $placeholders = implode(',', array_fill(0, count($filterFolderIds), '?'));
                    $whereExtra .= " AND EXISTS (SELECT 1 FROM ingestion_source_folders isf WHERE isf.ingestion_source_id = ki.ingestion_source_id AND isf.folder_id IN ($placeholders)) ";
                    $params = array_merge($params, $filterFolderIds);
                }
                
                // Role filtering
                $roleWhere = '';
                if (!empty($vectorRoles)) {
                    $rolePlaceholders = implode(',', array_fill(0, count($vectorRoles), '?'));
                    $roleWhere = " AND kc.chunk_role IN ($rolePlaceholders) ";
                    $params = array_merge($params, $vectorRoles);
                }
                
                // Token/char filtering
                $thresholdWhere = '';
                if ($minTokenCount > 0) {
                    $thresholdWhere .= " AND kc.token_count >= ? ";
                    $params = array_merge($params, [$minTokenCount]);
                }
                if ($minCharCount > 0) {
                    $thresholdWhere .= " AND CHAR_LENGTH(kc.chunk_text) >= ? ";
                    $params = array_merge($params, [$minCharCount]);
                }
                
                $sql =
                                        "SELECT kc.id, kc.knowledge_item_id, kc.chunk_text, kc.chunk_type, kc.chunk_role, kc.chunk_kind, kc.usage_policy, kc.authority, kc.confidence AS chunk_confidence, kc.time_horizon, kc.token_count, kc.domain, kc.actor, kc.tags, kc.source_variant,
                            (kc.embedding_vec <=> CAST(? AS vector)) AS distance,
                            ki.confidence AS item_confidence,
                            isrc.quality_score AS source_quality
                     FROM knowledge_chunks kc
                     JOIN knowledge_items ki ON ki.id = kc.knowledge_item_id
                     LEFT JOIN ingestion_sources isrc ON isrc.id = ki.ingestion_source_id
                                         WHERE kc.organization_id = ? AND kc.user_id = ? AND kc.embedding_vec IS NOT NULL
                                             AND kc.source_variant = 'normalized'
                                             AND kc.is_active = true
                                             AND (kc.usage_policy IS NULL OR kc.usage_policy <> 'never_generate')" . $roleWhere . $thresholdWhere . $whereExtra .
                    " ORDER BY kc.embedding_vec <=> CAST(? AS vector)
                      LIMIT ?";
                $rows = DB::select($sql, array_merge([$literal, $organizationId, $userId], $params, [$literal, max($topN, $limit * 3)]));

                // Early near-match detection and protection (before any ranking/filters)
                foreach ($rows as $r) {
                    $distance = max(0.0, (float) ($r->distance ?? 1.0));
                    $isNear = ($distance <= $nearMatchDistance);
                    $r->__distance = $distance;
                    $r->__near_match = $isNear;
                    $r->__protected = $isNear;
                }

                // Scoring (lower is better by using 1 - weighted_score)
                // Weights (sum=1.0): similarity=0.50, domain=0.15, role=0.15, authority=0.10, confidence=0.05, time=0.05
                $scored = array_map(function ($r) use ($retrievalDomain, $roleBoosts) {
                    $distance = max(0.0, (float) ($r->distance ?? 1.0));
                    // pgvector cosine distance ~= 1 - cosine_similarity (range [0..2]); clamp to [0..1]
                    $similarity = max(0.0, min(1.0, 1.0 - $distance));

                    $chunkDomain = strtolower(trim((string) ($r->domain ?? '')));
                    $qDomain = strtolower(trim((string) $retrievalDomain));
                    // Cheap but robust: containment match for open-vocabulary domains.
                    $domainMatch = (
                        $qDomain !== '' && $chunkDomain !== ''
                        && (str_contains($chunkDomain, $qDomain) || str_contains($qDomain, $chunkDomain))
                    ) ? 1.0 : 0.0;

                    $role = (string) ($r->chunk_role ?? '');
                    $roleScore = match ($role) {
                        'definition' => 1.0,
                        'strategic_claim' => 0.9,
                        'heuristic' => 0.8,
                        'causal_claim' => 0.7,
                        'instruction' => 0.6,
                        'metric' => 0.5,
                        default => 0.0,
                    };

                    $authority = (string) ($r->authority ?? 'medium');
                    $authorityScore = match ($authority) {
                        'high' => 1.0,
                        'low' => 0.2,
                        default => 0.6,
                    };

                    $chunkConf = (float) ($r->chunk_confidence ?? 0.5);
                    $itemConf = (float) ($r->item_confidence ?? 0.5);
                    $conf = max(0.0, min(1.0, (0.7 * $chunkConf) + (0.3 * $itemConf)));
                    $confidenceScore = $conf;

                    $time = (string) ($r->time_horizon ?? 'unknown');
                    $timeScore = match ($time) {
                        'current' => 1.0,
                        'near_term' => 0.8,
                        'long_term' => 0.6,
                        default => 0.5,
                    };

                    $weighted = (0.50 * $similarity)
                        + (0.15 * $domainMatch)
                        + (0.15 * $roleScore)
                        + (0.10 * $authorityScore)
                        + (0.05 * $confidenceScore)
                        + (0.05 * $timeScore);
                    $weighted = max(0.0, min(1.0, $weighted));
                    
                    // Apply role boost from config
                    $roleBoost = (float) ($roleBoosts[$role] ?? 1.0);
                    $weighted = min(1.0, $weighted * $roleBoost);

                    // Preserve debug fields for downstream selection logic
                    $r->__similarity = (float) $similarity;
                    $r->__domain_match = (float) $domainMatch;
                    $r->__role_score = (float) $roleScore;
                    $r->__authority_score = (float) $authorityScore;
                    $r->__confidence_score = (float) $confidenceScore;
                    $r->__time_score = (float) $timeScore;
                    $r->__weighted_score = (float) $weighted;
                    $r->__role_boost = (float) $roleBoost;

                    // Keep existing pipeline contract: lower is better
                    $r->__composite = (float) (1.0 - $weighted);
                    $r->__distance = (float) $distance;
                    return $r;
                }, $rows);

                usort($scored, fn($a, $b) => $a->__composite <=> $b->__composite);

                // Two-stage pipeline: Stage 1 ranking (recall-first), Stage 2 selection (precision)
                $topKSize = max($limit * 3, $topN);
                $ranked = $scored; // already sorted by composite
                $topK = array_slice($ranked, 0, $topKSize);
                // Preserve a Top-K view by raw distance (from DB order) for sparse-detection triggers
                $topKByDistance = array_slice($rows, 0, $topKSize);

                // Ensure near-perfect matches are not ranked out of Top-K by heuristics
                $nearMatches = array_values(array_filter($scored, fn($r) => !empty($r->__near_match)));
                if (!empty($nearMatches)) {
                    $present = [];
                    foreach ($topK as $r) { $present[(string)($r->id ?? '')] = true; }
                    $prepend = [];
                    foreach ($nearMatches as $nm) {
                        $id = (string) ($nm->id ?? '');
                        if ($id !== '' && empty($present[$id])) {
                            $nm->__near_override = true;
                            $prepend[] = $nm;
                            $present[$id] = true;
                        }
                    }
                    if (!empty($prepend)) {
                        $topK = array_merge($prepend, $topK);
                        // keep window bounded
                        $topK = array_slice($topK, 0, $topKSize);
                    }
                }

                // Observational-only soft score: tag candidates but never hard filter
                foreach ($topK as $r) {
                    $r->__soft_rejected = ((float) ($r->__composite ?? 0.0)) > $softScoreLimit;
                }

                // Apply preferences within Top-K only: prefer normalized variant without removing candidates
                // Build a stable list that biases normalized before raw for the same knowledge_item (except for story/example intents)
                $preferNormalized = !in_array($intent, ['story','example'], true);
                if ($preferNormalized) {
                    // Stable sort by: composite score asc (already), then variant preference within same KI
                    usort($topK, function ($a, $b) {
                        $cmp = ($a->__composite <=> $b->__composite);
                        if ($cmp !== 0) return $cmp;
                        $aKi = (string) ($a->knowledge_item_id ?? '');
                        $bKi = (string) ($b->knowledge_item_id ?? '');
                        if ($aKi !== '' && $aKi === $bKi) {
                            $aNorm = ((string) ($a->source_variant ?? 'raw')) === 'normalized';
                            $bNorm = ((string) ($b->source_variant ?? 'raw')) === 'normalized';
                            if ($aNorm !== $bNorm) {
                                // normalized first
                                return $aNorm ? -1 : 1;
                            }
                        }
                        return 0;
                    });
                }

                // Selection: Protected-first with excerpt cap handling (protected may bypass cap)
                $excerptCap = (int) (config('vector.retrieval.max_excerpt_chunks', 2));
                $excerptUsed = 0;
                $final = [];
                // Partition protected vs non-protected
                $protected = array_values(array_filter($topK, fn($r) => !empty($r->__near_match)));
                $nonProtected = array_values(array_filter($topK, fn($r) => empty($r->__near_match)));
                // Sort protected by raw distance for deterministic order among protected
                usort($protected, function($a, $b) {
                    $da = (float) ($a->distance ?? $a->__distance ?? 1.0);
                    $db = (float) ($b->distance ?? $b->__distance ?? 1.0);
                    return $da <=> $db;
                });
                if (count($protected) > $limit) {
                    try {
                        Log::warning('retriever.protected_overflow', [
                            'query' => $query,
                            'protected_count' => count($protected),
                            'return_k' => $limit,
                        ]);
                    } catch (\Throwable $e) {}
                }
                // Include protected first; allow excerpt to bypass cap
                foreach ($protected as $row) {
                    if ((string) ($row->chunk_type ?? '') === 'excerpt') {
                        if ($excerptUsed >= $excerptCap) { $row->__excerpt_cap_bypassed = true; }
                        else { $excerptUsed++; }
                    }
                    $final[] = $row;
                }
                // Fill remainder with non-protected honoring excerpt cap
                foreach ($nonProtected as $row) {
                    if ((string) ($row->chunk_type ?? '') === 'excerpt') {
                        if ($excerptUsed >= $excerptCap) { continue; }
                        $excerptUsed++;
                    }
                    $final[] = $row;
                }

                // Sparse-Document Minimum Recall Guarantee (SD-MRG)
                $sparseCfg = (array) config('ai.retriever.sparse_recall', []);
                $sparseEnabled = (bool) ($sparseCfg['enabled'] ?? false);
                if ($sparseEnabled && !empty($topKByDistance)) {
                    $threshold = (int) ($sparseCfg['chunk_threshold'] ?? 2);
                    $distanceCeiling = (float) ($sparseCfg['distance_ceiling'] ?? 0.20);
                    $maxInjections = max(0, (int) ($sparseCfg['max_injections'] ?? 1));

                    // Build chunk counts per KI for KIs present in the Top-K-by-distance pool
                    $kiIds = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki !== '') { $kiIds[$ki] = true; }
                    }
                    $chunkCounts = [];
                    if (!empty($kiIds)) {
                        $ids = array_keys($kiIds);
                        // Chunk counts for present KIs
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $countRows = DB::select("SELECT knowledge_item_id AS ki, COUNT(*) AS cnt FROM knowledge_chunks WHERE knowledge_item_id IN ($placeholders) AND is_active = true AND (usage_policy IS NULL OR usage_policy <> 'never_generate') GROUP BY knowledge_item_id", $ids);
                        foreach ($countRows as $cr) { $chunkCounts[(string) $cr->ki] = (int) $cr->cnt; }
                    }

                    // Detect sparse candidates from the raw-distance Top-K window
                    $sparseCandidates = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki === '') { continue; }
                        $count = (int) ($chunkCounts[$ki] ?? 0);
                        $dist = (float) ($r->distance ?? $r->__distance ?? 1.0);
                        if ($count <= $threshold && $dist <= $distanceCeiling) {
                            $sparseCandidates[$ki][] = $r;
                        }
                    }

                    // Helper: check if final already has a protected chunk from KI
                    $hasProtectedInFinal = function(array $arr, string $ki): bool {
                        foreach ($arr as $x) {
                            if ((string) ($x->knowledge_item_id ?? '') === $ki && !empty($x->__near_match)) { return true; }
                        }
                        return false;
                    };
                    // Helper: find replaceable (non-protected) index for KI with worst distance
                    $findReplaceIndex = function(array $arr, string $ki) {
                        $worstIdx = null; $worstDist = -1.0;
                        foreach ($arr as $i => $x) {
                            if ((string) ($x->knowledge_item_id ?? '') !== $ki) { continue; }
                            if (!empty($x->__near_match)) { return null; }
                            $d = (float) ($x->distance ?? $x->__distance ?? 1.0);
                            if ($d > $worstDist) { $worstDist = $d; $worstIdx = $i; }
                        }
                        return $worstIdx;
                    };
                    // Helper: min by raw distance
                    $minByDistance = function(array $arr) { usort($arr, function($a, $b) { $da = (float) ($a->distance ?? $a->__distance ?? 1.0); $db = (float) ($b->distance ?? $b->__distance ?? 1.0); return $da <=> $db; }); return $arr[0] ?? null; };
                    // Heuristic: if query likely numeric/price, prefer 'metric' role within KI
                    $chooseBestForQuery = function(array $arr) use ($minByDistance, $query) {
                        $hintNumeric = (bool) preg_match('/(\$|\d|price|cost|amount|million|billion)/i', (string) $query);
                        if ($hintNumeric) {
                            $metrics = array_values(array_filter($arr, fn($x) => (string) ($x->chunk_role ?? '') === 'metric'));
                            if (!empty($metrics)) { return $minByDistance($metrics); }
                        }
                        return $minByDistance($arr);
                    };

                    $injected = 0;
                    foreach ($sparseCandidates as $ki => $chunksForKi) {
                        if ($injected >= $maxInjections) { break; }
                        // Allow injection unless a protected candidate from this KI already selected
                        if ($hasProtectedInFinal($final, $ki)) { continue; }
                        $best = $chooseBestForQuery($chunksForKi);
                        if ($best === null) { continue; }
                        // Replace existing non-protected from same KI if new is better by distance; else inject
                        $replaceIdx = $findReplaceIndex($final, $ki);
                        if ($replaceIdx !== null) {
                            $existing = $final[$replaceIdx];
                            $existingDist = (float) ($existing->distance ?? $existing->__distance ?? 1.0);
                            $bestDist = (float) ($best->distance ?? $best->__distance ?? 1.0);
                            if ($bestDist < $existingDist) {
                                // Adjust excerpt accounting if needed
                                if ((string) ($existing->chunk_type ?? '') === 'excerpt' && (string) ($best->chunk_type ?? '') !== 'excerpt') { $excerptUsed = max(0, $excerptUsed - 1); }
                                if ((string) ($existing->chunk_type ?? '') !== 'excerpt' && (string) ($best->chunk_type ?? '') === 'excerpt') {
                                    if ($excerptUsed >= $excerptCap) { /* cannot replace with excerpt if cap reached */ }
                                    else { $excerptUsed++; }
                                }
                                $best->__recall_injected = true;
                                $final[$replaceIdx] = $best;
                                $injected++;
                                try { Log::info('retriever.sparse_recall_replace', ['org' => $organizationId, 'user' => $userId, 'knowledge_item_id' => $ki]); } catch (\Throwable $e) {}
                            } else {
                                continue;
                            }
                        } else {
                            // Respect excerpt caps even for fresh injections
                            if ((string) ($best->chunk_type ?? '') === 'excerpt' && $excerptUsed >= $excerptCap) { continue; }
                            $best->__recall_injected = true;
                            if ((string) ($best->chunk_type ?? '') === 'excerpt') { $excerptUsed++; }
                            $final[] = $best;
                            $injected++;
                        }
                        // Emit observability event per injection
                        try {
                            Log::info('retriever.sparse_recall_injection', [
                                'org' => $organizationId,
                                'user' => $userId,
                                'knowledge_item_id' => (string) ($best->knowledge_item_id ?? ''),
                                'chunk_id' => (string) ($best->id ?? ''),
                                'distance' => (float) ($best->distance ?? $best->__distance ?? 0.0),
                                'reason' => 'sparse_doc_recall',
                            ]);
                        } catch (\Throwable $e) {}
                    }
                }

                // Small-Dense Assist (anti-crowding)
                $sdaCfg = (array) config('ai.retriever.small_dense_assist', []);
                $sdaEnabled = (bool) ($sdaCfg['enabled'] ?? false);
                if ($sdaEnabled && !empty($topKByDistance)) {
                    $minChunks = (int) ($sdaCfg['chunk_threshold_min'] ?? 3);
                    $maxChunks = (int) ($sdaCfg['chunk_threshold_max'] ?? 6);
                    $distanceCeiling = (float) ($sdaCfg['distance_ceiling'] ?? 0.15);
                    $maxInjections = max(0, (int) ($sdaCfg['max_injections'] ?? 1));

                    // Build chunk counts per KI for KIs present in the Top-K-by-distance pool
                    $kiIds = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki !== '') { $kiIds[$ki] = true; }
                    }
                    $chunkCounts = [];
                    if (!empty($kiIds)) {
                        $ids = array_keys($kiIds);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $countRows = DB::select("SELECT knowledge_item_id AS ki, COUNT(*) AS cnt FROM knowledge_chunks WHERE knowledge_item_id IN ($placeholders) AND is_active = true AND (usage_policy IS NULL OR usage_policy <> 'never_generate') GROUP BY knowledge_item_id", $ids);
                        foreach ($countRows as $cr) { $chunkCounts[(string) $cr->ki] = (int) $cr->cnt; }
                    }

                    // Detect small-dense candidates
                    $candidates = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki === '') { continue; }
                        $count = (int) ($chunkCounts[$ki] ?? 0);
                        $dist = (float) ($r->distance ?? $r->__distance ?? 1.0);
                        if ($count >= $minChunks && $count <= $maxChunks && $dist <= $distanceCeiling) {
                            $candidates[$ki][] = $r;
                        }
                    }

                    // Only block injection if a protected (near-match) candidate for this KI is already selected
                    $hasProtectedInFinal = function(array $arr, string $ki): bool {
                        foreach ($arr as $x) {
                            if ((string) ($x->knowledge_item_id ?? '') === $ki && !empty($x->__near_match)) {
                                return true;
                            }
                        }
                        return false;
                    };
                    $minByDistance = function(array $arr) { usort($arr, function($a, $b) { $da = (float) ($a->distance ?? $a->__distance ?? 1.0); $db = (float) ($b->distance ?? $b->__distance ?? 1.0); return $da <=> $db; }); return $arr[0] ?? null; };
                    // Heuristic: prefer 'metric' role for numeric/price-like queries
                    $chooseBestForQuery = function(array $arr) use ($minByDistance, $query) {
                        $hintNumeric = (bool) preg_match('/(\$|\d|price|cost|amount|million|billion)/i', (string) $query);
                        if ($hintNumeric) {
                            $metrics = array_values(array_filter($arr, fn($x) => (string) ($x->chunk_role ?? '') === 'metric'));
                            if (!empty($metrics)) { return $minByDistance($metrics); }
                        }
                        return $minByDistance($arr);
                    };

                    $injected = 0;
                    // Helper: find replaceable (non-protected) index for KI with worst distance
                    $findReplaceIndex = function(array $arr, string $ki) {
                        $worstIdx = null; $worstDist = -1.0;
                        foreach ($arr as $i => $x) {
                            if ((string) ($x->knowledge_item_id ?? '') !== $ki) { continue; }
                            if (!empty($x->__near_match)) { return null; }
                            $d = (float) ($x->distance ?? $x->__distance ?? 1.0);
                            if ($d > $worstDist) { $worstDist = $d; $worstIdx = $i; }
                        }
                        return $worstIdx;
                    };
                    foreach ($candidates as $ki => $chunksForKi) {
                        if ($injected >= $maxInjections) { break; }
                        // Allow injection even if KI is present, unless a protected candidate is already selected
                        if ($hasProtectedInFinal($final, $ki)) { continue; }
                        $best = $chooseBestForQuery($chunksForKi);
                        if ($best === null) { continue; }
                        // Replace existing non-protected from same KI if better by distance; else inject
                        $replaceIdx = $findReplaceIndex($final, $ki);
                        if ($replaceIdx !== null) {
                            $existing = $final[$replaceIdx];
                            $existingDist = (float) ($existing->distance ?? $existing->__distance ?? 1.0);
                            $bestDist = (float) ($best->distance ?? $best->__distance ?? 1.0);
                            if ($bestDist < $existingDist) {
                                if ((string) ($existing->chunk_type ?? '') === 'excerpt' && (string) ($best->chunk_type ?? '') !== 'excerpt') { $excerptUsed = max(0, $excerptUsed - 1); }
                                if ((string) ($existing->chunk_type ?? '') !== 'excerpt' && (string) ($best->chunk_type ?? '') === 'excerpt') {
                                    if ($excerptUsed >= $excerptCap) { /* cannot replace with excerpt if cap reached */ }
                                    else { $excerptUsed++; }
                                }
                                $best->__recall_injected = true;
                                $best->__assist_reason = 'small_dense_assist';
                                $final[$replaceIdx] = $best;
                                $injected++;
                                try { Log::info('retriever.small_dense_replace', ['org' => $organizationId, 'user' => $userId, 'knowledge_item_id' => $ki]); } catch (\Throwable $e) {}
                            } else {
                                continue;
                            }
                        } else {
                            if ((string) ($best->chunk_type ?? '') === 'excerpt' && $excerptUsed >= $excerptCap) { continue; }
                            $best->__recall_injected = true;
                            $best->__assist_reason = 'small_dense_assist';
                            if ((string) ($best->chunk_type ?? '') === 'excerpt') { $excerptUsed++; }
                            $final[] = $best;
                            $injected++;
                        }
                        try {
                            Log::info('retriever.small_dense_injection', [
                                'org' => $organizationId,
                                'user' => $userId,
                                'knowledge_item_id' => (string) ($best->knowledge_item_id ?? ''),
                                'chunk_id' => (string) ($best->id ?? ''),
                                'distance' => (float) ($best->distance ?? $best->__distance ?? 0.0),
                                'reason' => 'small_dense_assist',
                            ]);
                        } catch (\Throwable $e) {}
                    }
                }

                // Stable-unique by chunk id, then truncate to $limit
                $seen = [];
                $final = array_values(array_filter($final, function ($r) use (&$seen) {
                    $id = (string) ($r->id ?? '');
                    if ($id === '' || isset($seen[$id])) { return false; }
                    $seen[$id] = true; return true;
                }));
                $final = array_slice($final, 0, $limit);

                // Hard invariant guard: protected must survive
                if (!empty($protected)) {
                    $present = array_filter($final, fn($f) => !empty($f->__protected) || !empty($f->__near_match));
                    if (empty($present)) {
                        try {
                            Log::error('retriever.invariant_violation.protected_dropped', [
                                'query' => $query,
                                'protected_ids' => array_map(fn($p) => $p->id ?? null, $protected),
                            ]);
                        } catch (\Throwable $e) {}
                    }
                }

                // Observability
                try {
                    $distances = array_map(fn($r) => round((float) ($r->distance ?? 0), 4), $rows);
                    Log::info('retriever.semantic', [
                        'org' => $organizationId,
                        'user' => $userId,
                        'intent' => $intent,
                        'limit' => $limit,
                        'top_n' => $topN,
                        'returned' => count($rows),
                        'after_selection' => count($final),
                        'excerpt_cap' => $excerptCap,
                        'soft_score_limit' => $softScoreLimit,
                        'soft_rejected_in_topk' => count(array_filter($topK, fn($r) => !empty($r->__soft_rejected))),
                        'distances' => array_slice($distances, 0, 5),
                        'sparse_recall' => (array) config('ai.retriever.sparse_recall'),
                        'retrieval_policy' => [
                            'vector_roles' => $vectorRoles,
                            'min_token_count' => $minTokenCount,
                            'min_char_count' => $minCharCount,
                            'role_boosts_enabled' => !empty($roleBoosts),
                        ],
                    ]);
                } catch (\Throwable $e) {}
                if (!empty($final)) {
                    return array_map(fn($r) => [
                        'id' => $r->id,
                        'knowledge_item_id' => $r->knowledge_item_id,
                        'chunk_text' => $r->chunk_text,
                        'chunk_type' => $r->chunk_type,
                        'chunk_role' => $r->chunk_role,
                        'chunk_kind' => $r->chunk_kind ?? null,
                        'usage_policy' => $r->usage_policy ?? null,
                        'authority' => $r->authority ?? null,
                        'confidence' => $r->chunk_confidence ?? null,
                        'time_horizon' => $r->time_horizon ?? null,
                        'token_count' => $r->token_count ?? null,
                        'source_variant' => $r->source_variant ?? null,
                        'tags' => is_array($r->tags) ? $r->tags : json_decode((string) $r->tags, true),
                        'score' => isset($r->__composite) ? (float) $r->__composite : null,
                        'recall_injected' => !empty($r->__recall_injected),
                    ], $final);
                }
            }
        } catch (\Throwable $e) {
            // swallow and fall back
        }

        // Fallback: keyword search
        $qb = KnowledgeChunk::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('chunk_text', 'like', '%' . substr($query, 0, 64) . '%')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('usage_policy')
                    ->orWhere('usage_policy', '!=', 'never_generate');
            });

        if (!empty($filterKiIds)) { $qb->whereIn('knowledge_item_id', $filterKiIds); }

        if (!empty($filterFolderIds) && Schema::hasTable('ingestion_source_folders')) {
            $qb->whereIn('knowledge_item_id', function ($q) use ($filterFolderIds) {
                $q->select('ki.id')
                    ->distinct()
                    ->from('knowledge_items as ki')
                    ->join('ingestion_source_folders as isf', 'isf.ingestion_source_id', '=', 'ki.ingestion_source_id')
                    ->whereIn('isf.folder_id', $filterFolderIds);
            });
        }

        return $qb->limit($limit)
            ->get(['id','knowledge_item_id','chunk_text','chunk_type','chunk_role','chunk_kind','usage_policy','authority','confidence','time_horizon','token_count','tags'])
            ->toArray();
    }

    /**
     * Enrichment retrieval: fetch related metric/instruction chunks from the same
     * knowledge items as the primary chunks, without using vector search.
     * 
     * This keeps output relevant while avoiding "metric atoms" becoming primary hits.
     * 
     * @param array $topChunks Primary chunks already selected
     * @param int $limit Maximum enrichment chunks to return
     * @return array Enrichment chunks
     */
    public function enrichForChunks(array $topChunks, int $limit = 10): array
    {
        if (empty($topChunks)) {
            return [];
        }
        
        $config = (array) config('ai_chunk_roles.enrichment', []);
        if (!($config['enabled'] ?? false)) {
            return [];
        }
        
        $enrichmentRoles = (array) ($config['roles'] ?? ['metric', 'instruction']);
        $maxPerItem = (int) ($config['max_per_item'] ?? 3);
        $maxTotal = min($limit, (int) ($config['max_total'] ?? 10));
        
        // Collect knowledge_item_ids from selected chunks
        $kiIds = [];
        foreach ($topChunks as $chunk) {
            $kiId = is_array($chunk) 
                ? ($chunk['knowledge_item_id'] ?? null)
                : ($chunk->knowledge_item_id ?? null);
            if ($kiId) {
                $kiIds[] = (string) $kiId;
            }
        }
        
        if (empty($kiIds)) {
            return [];
        }
        
        $kiIds = array_values(array_unique($kiIds));
        
        // Fetch enrichment chunks
        $chunks = KnowledgeChunk::query()
            ->whereIn('knowledge_item_id', $kiIds)
            ->whereIn('chunk_role', $enrichmentRoles)
            ->where('source_variant', 'normalized')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('usage_policy')
                    ->orWhere('usage_policy', '!=', 'never_generate');
            })
            ->orderByDesc('confidence')
            ->orderByDesc('token_count')
            ->limit($maxTotal)
            ->get(['id', 'knowledge_item_id', 'chunk_text', 'chunk_type', 'chunk_role', 'authority', 'confidence', 'time_horizon', 'domain', 'actor', 'tags'])
            ->toArray();
        
        // Mark these as enrichment tier
        foreach ($chunks as &$chunk) {
            $chunk['retrieval_tier'] = 'enrichment';
        }
        
        return $chunks;
    }

    public function businessFacts(string $organizationId, string $userId, int $limit = 8): array
    {
        return BusinessFact::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get(['id','type','text','confidence'])
            ->toArray();
    }

    public function businessProfileSnapshot(string $organizationId): array
    {
        try {
            $org = \App\Models\Organization::findOrFail($organizationId);
            return (array) ((array) ($org->settings ?? []))['business_profile_snapshot'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function swipeStructures(
        string $organizationId,
        ?string $intent,
        ?string $funnelStage,
        string $platform,
        string $prompt,
        int $limit = 2,
        ?array $structureSignature = null,
        ?float $threshold = null
    ): array
    {
        // Canonical-first: do NOT gate by intent. Intent/funnel are soft boosts in scoring.
        $base = SwipeStructure::query()
            ->where(function ($q) use ($organizationId) {
                // Prefer direct org scoping when column exists; fallback for older schemas.
                try {
                    if (Schema::hasColumn('swipe_structures', 'organization_id')) {
                        $q->where('organization_id', $organizationId);
                        return;
                    }
                } catch (\Throwable) {
                    // fall back
                }
                $q->whereHas('swipeItem', fn($qq) => $qq->where('organization_id', $organizationId));
            })
            ->where(function ($q) {
                // Keep backward compatibility if these columns don't exist yet in some envs
                try {
                    $q->where(function ($qq) {
                        $qq->whereNull('deleted_at');
                    });
                } catch (\Throwable) {
                    // ignore
                }
            });

        // Prefer canonical rows (ephemeral rows should not be selected automatically)
        try { $base->where('is_ephemeral', false); } catch (\Throwable) {}

        // Order by confidence + usage signals (if present)
        $base->orderByDesc('confidence');
        try { $base->orderByDesc('success_count'); } catch (\Throwable) {}
        try { $base->orderByDesc('last_used_at'); } catch (\Throwable) {}

        // Always pick ONE structure (the rest of the pipeline assumes at most one for guidance)
        $limit = 1;

        $candidateLimit = (int) config('swipe.structure_candidate_limit', 10);
        $minFitScore = (int) config('swipe.structure_min_fit_score', 55);

        // Candidate retrieval (max N); structural-fit-first scoring
        $candidates = $base
            ->limit(max(1, $candidateLimit))
            ->get(['id','intent','funnel_stage','cta_type','structure','confidence'])
            ->map(fn($m) => $m->toArray())
            ->all();

        $scores = [];
        $best = null;
        $bestScore = -1;

        // Derive a soft length band from the requested signature when available.
        $lengthBand = $this->deriveLengthBandFromSignature($structureSignature);
        $shapeHint = $this->deriveShapeHintFromSignature($structureSignature);

        foreach ($candidates as $c) {
            $fit = $this->fitScore(
                candidate: (array) $c,
                requestedLengthBand: $lengthBand,
                requestedShapeHint: $shapeHint,
                requestedIntent: $intent,
                requestedFunnelStage: $funnelStage,
                ctaRequired: false
            );
            $scores[(string) ($c['id'] ?? '')] = $fit;
            if ($fit > $bestScore) {
                $bestScore = $fit;
                $best = $c;
            }
        }

        // Accept a canonical match if it clears min fit
        if (is_array($best) && $bestScore >= $minFitScore) {
            $best['fit_score'] = $bestScore;
            $best['structure_resolution'] = 'auto_matched';
            return [
                'selected' => [$best],
                'scores' => $scores,
                'rejected' => array_filter($scores, fn($v) => (int) $v < $minFitScore),
            ];
        }

        // Fallback: generate ephemeral structure (never persisted here)
        $fallback = $this->ephemeralStructureGenerator->generate(
            prompt: (string) ($prompt ?? ''),
            requestedIntent: $intent,
            requestedLengthBand: $lengthBand,
            requestedShapeHint: $shapeHint,
        );

        $ephemeral = [
            'id' => null,
            'intent' => (string) ($intent ?? ''),
            'funnel_stage' => (string) ($funnelStage ?? ''),
            'cta_type' => 'none',
            'structure' => (array) ($fallback['structure'] ?? []),
            'confidence' => 0.3,
            'fit_score' => $bestScore >= 0 ? $bestScore : null,
            'structure_resolution' => 'ephemeral_fallback',
            'origin' => 'ephemeral',
        ];

        $model = (string) (($fallback['meta']['model'] ?? '') ?: '');

        return [
            'selected' => [$ephemeral],
            'scores' => $scores,
            'rejected' => $scores,
            'ephemeral_meta' => [
                'model' => $model !== '' ? $model : null,
                'source' => $fallback['meta']['source'] ?? null,
                'usage' => $fallback['meta']['usage'] ?? null,
            ],
        ];
    }

    private function deriveLengthBandFromSignature(?array $signature): ?string
    {
        if (!is_array($signature) || empty($signature)) {
            return null;
        }
        $n = count($signature);
        if ($n <= 5) { return 'short'; }
        if ($n <= 7) { return 'medium'; }
        return 'long';
    }

    private function deriveShapeHintFromSignature(?array $signature): ?string
    {
        if (!is_array($signature) || empty($signature)) {
            return null;
        }
        $joined = mb_strtolower(json_encode($signature) ?: '');
        if (str_contains($joined, 'story')) { return 'story'; }
        if (str_contains($joined, 'list') || str_contains($joined, 'steps') || str_contains($joined, 'takeaways')) { return 'list'; }
        if (str_contains($joined, 'argument') || str_contains($joined, 'contrarian')) { return 'argument'; }
        return null;
    }

    private function fitScore(
        array $candidate,
        ?string $requestedLengthBand,
        ?string $requestedShapeHint,
        ?string $requestedIntent,
        ?string $requestedFunnelStage,
        bool $ctaRequired = false
    ): int {
        $sections = is_array(($candidate['structure'] ?? null)) ? (array) $candidate['structure'] : [];
        $count = count($sections);

        // Count score (0-40)
        $countScore = 20;
        if ($requestedLengthBand) {
            $range = match ($requestedLengthBand) {
                'short' => [3, 5],
                'medium' => [4, 7],
                'long' => [6, 10],
                default => [3, 8],
            };
            [$min, $max] = $range;
            if ($count >= $min && $count <= $max) {
                $countScore = 40;
            } else {
                $dist = ($count < $min) ? ($min - $count) : ($count - $max);
                $countScore = max(0, 40 - ($dist * 10));
            }
        } else {
            // Prefer 4-7 when unknown
            $countScore = ($count >= 4 && $count <= 7) ? 30 : 15;
        }

        // Shape score (0-30)
        $shapeScore = 0;
        $blob = mb_strtolower(json_encode($sections) ?: '');
        if ($requestedShapeHint === 'story') {
            $shapeScore = (str_contains($blob, 'pivot') || str_contains($blob, 'turn') || str_contains($blob, 'reframe') || str_contains($blob, 'twist')) ? 30 : 10;
        } elseif ($requestedShapeHint === 'list') {
            $shapeScore = (str_contains($blob, 'list') || str_contains($blob, 'steps') || str_contains($blob, 'breakdown') || str_contains($blob, 'takeaways')) ? 30 : 10;
        } elseif ($requestedShapeHint) {
            $shapeScore = 10;
        }

        // CTA score (0-10)
        $ctaScore = 0;
        $hasCta = (str_contains($blob, 'cta') || str_contains($blob, 'call to action') || str_contains($blob, 'subscribe') || str_contains($blob, 'follow'));
        if ($ctaRequired) {
            $ctaScore = $hasCta ? 10 : 0;
        } else {
            $ctaScore = $hasCta ? 6 : 0;
        }

        // Simplicity score (0-10)
        $simplicityScore = 10;
        if ($requestedLengthBand === 'short' && $count > 7) {
            $simplicityScore = 0;
        } elseif ($requestedLengthBand === 'short' && $count > 5) {
            $simplicityScore = 5;
        }

        // Bias boosts (+0-5 each)
        $intentBoost = (!empty($requestedIntent) && !empty($candidate['intent']) && (string) $candidate['intent'] === (string) $requestedIntent) ? 5 : 0;
        $funnelBoost = (!empty($requestedFunnelStage) && !empty($candidate['funnel_stage']) && (string) $candidate['funnel_stage'] === (string) $requestedFunnelStage) ? 5 : 0;

        $score = $countScore + $shapeScore + $ctaScore + $simplicityScore + $intentBoost + $funnelBoost;
        return (int) max(0, min(100, $score));
    }

    private function extractSections(array $structure): array
    {
        // Accept formats like ['sections'=>['Hook','Conflict','Resolution']] or flat arrays
        $sections = [];
        if (isset($structure['sections']) && is_array($structure['sections'])) {
            $sections = $structure['sections'];
        } elseif (!empty($structure)) {
            $sections = $structure;
        }
        return array_values(array_filter(array_map(function ($s) {
            $s = is_string($s) ? $s : (is_array($s) && isset($s['name']) ? (string) $s['name'] : '');
            $s = mb_strtolower(trim($s));
            return $s;
        }, $sections), fn($s) => $s !== ''));
    }

    private function jaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) && empty($b)) return 1.0;
        if (empty($a) || empty($b)) return 0.0;
        $setA = array_values(array_unique($a));
        $setB = array_values(array_unique($b));
        $intersect = array_intersect($setA, $setB);
        $union = array_unique(array_merge($setA, $setB));
        $j = count($union) > 0 ? (count($intersect) / count($union)) : 0.0;
        // Small boost for order alignment (prefix match)
        $prefixBonus = 0.0;
        $maxPrefix = min(count($setA), count($setB));
        $match = 0;
        for ($i = 0; $i < $maxPrefix; $i++) {
            if ($setA[$i] === $setB[$i]) { $match++; } else { break; }
        }
        if ($maxPrefix > 0) {
            $prefixBonus = min(0.2, $match / max(1, $maxPrefix) * 0.2);
        }
        return min(1.0, $j + $prefixBonus);
    }

    /**
     * Variant of knowledgeChunks that also returns an explainability trace per chunk.
     * @return array{chunks: array<int, array>, trace: array<int, array>}
     */
    public function knowledgeChunksTrace(string $organizationId, string $userId, string $query, string $intent, int $limit = 5): array
    {
        $query = trim((string) $query);
        $intentCaps = (array) config('vector.retrieval.max_per_intent', [
            'educational' => 5,
            'persuasive' => 4,
            'story' => 6,
            'contrarian' => 5,
            'emotional' => 5,
            '*' => 5,
        ]);
        $cap = (int) ($intentCaps[$intent] ?? $intentCaps['*'] ?? 5);
        $limit = max(1, min($limit, $cap, 20));
        $topN = (int) (config('ai.retriever.top_n', 20));
        $softScoreLimit = (float) (config('ai.retriever.soft_score_limit', 0.90));
        $weights = (array) (config('ai.retriever.weights', [
            'distance' => 0.6,
            'authority' => 0.15,
            'confidence' => 0.15,
            'time_horizon' => 0.10,
        ]));
        $nearMatchDistance = (float) (config('ai.retriever.near_match_distance', 0.10));

        if ($query === '') {
            $rows = KnowledgeChunk::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('usage_policy')
                        ->orWhere('usage_policy', '!=', 'never_generate');
                })
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id','chunk_text','chunk_type','tags'])
                ->toArray();
            return [
                'chunks' => $rows,
                'trace' => array_map(fn($r) => [
                    'chunk_id' => (string) ($r['id'] ?? ''),
                    'score' => 0.0,
                    'factors' => [
                        'distance' => 0.0,
                        'authority_penalty' => 0.0,
                        'confidence_penalty' => 0.0,
                        'time_penalty' => 0.0,
                        'weights' => [
                            'distance' => (float) ($weights['distance'] ?? 0.6),
                            'authority' => (float) ($weights['authority'] ?? 0.15),
                            'confidence' => (float) ($weights['confidence'] ?? 0.15),
                            'time_horizon' => (float) ($weights['time_horizon'] ?? 0.10),
                        ],
                    ],
                ], $rows),
            ];
        }

        try {
            $embed = app(EmbeddingsService::class)->embedOne($query);
            if (!empty($embed)) {
                $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float)$f), '0'), $embed)) . ']';
                $rows = DB::select(
                    "SELECT kc.id, kc.knowledge_item_id, kc.chunk_text, kc.chunk_type, kc.chunk_role, kc.authority, kc.confidence AS chunk_confidence, kc.time_horizon, kc.tags, kc.source_variant,
                            (kc.embedding_vec <=> CAST(? AS vector)) AS distance,
                            ki.confidence AS item_confidence,
                            isrc.quality_score AS source_quality
                     FROM knowledge_chunks kc
                     JOIN knowledge_items ki ON ki.id = kc.knowledge_item_id
                     LEFT JOIN ingestion_sources isrc ON isrc.id = ki.ingestion_source_id
                       WHERE kc.organization_id = ? AND kc.user_id = ? AND kc.embedding_vec IS NOT NULL
                       AND kc.is_active = true
                       AND (kc.usage_policy IS NULL OR kc.usage_policy <> 'never_generate')
                       ORDER BY kc.embedding_vec <=> CAST(? AS vector)
                       LIMIT ?",
                    [$literal, $organizationId, $userId, $literal, max($topN, $limit * 3)]
                );
                // Early near-match detection/protection before ranking
                foreach ($rows as $r) {
                    $distance = max(0.0, (float) ($r->distance ?? 1.0));
                    $isNear = ($distance <= $nearMatchDistance);
                    $r->__distance = $distance;
                    $r->__near_match = $isNear;
                    $r->__protected = $isNear;
                }
                // Composite score ordering and selection
                $scored = array_map(function ($r) use ($weights, $nearMatchDistance) {
                    $distance = max(0.0, (float) ($r->distance ?? 1.0));
                    $authority = (string) ($r->authority ?? 'medium');
                    $chunkConf = (float) ($r->chunk_confidence ?? 0.5);
                    $itemConf = (float) ($r->item_confidence ?? 0.5);
                    $time = (string) ($r->time_horizon ?? 'unknown');
                    $authorityPenalty = match ($authority) {
                        'high' => 0.00,
                        'low' => 0.08,
                        default => 0.04,
                    };
                    $confidencePenalty = 0.7 * max(0.0, 1.0 - max(min($chunkConf, 1.0), 0.0)) + 0.3 * max(0.0, 1.0 - max(min($itemConf, 1.0), 0.0));
                    $timePenalty = match ($time) {
                        'current' => 0.00,
                        'near_term' => 0.02,
                        'long_term' => 0.05,
                        default => 0.04,
                    };
                    $score = (($weights['distance'] ?? 0.6) * $distance)
                        + (($weights['authority'] ?? 0.15) * $authorityPenalty)
                        + (($weights['confidence'] ?? 0.15) * $confidencePenalty)
                        + (($weights['time_horizon'] ?? 0.10) * $timePenalty);
                    if ($distance <= $nearMatchDistance) {
                        $score = ($weights['distance'] ?? 0.6) * $distance;
                        $r->__near_match = true;
                        $r->__protected = true;
                    } else {
                        $r->__near_match = false;
                        $r->__protected = false;
                    }
                    $r->__composite = (float) $score;
                    $r->__distance = (float) $distance;
                    $r->__authority_penalty = (float) $authorityPenalty;
                    $r->__confidence_penalty = (float) $confidencePenalty;
                    $r->__time_penalty = (float) $timePenalty;
                    return $r;
                }, $rows);
                usort($scored, fn($a, $b) => $a->__composite <=> $b->__composite);

                // Stage 1: Top-K by composite
                $topKSize = max($limit * 3, $topN);
                $topK = array_slice($scored, 0, $topKSize);
                $topKByDistance = array_slice($rows, 0, $topKSize);

                // Ensure near-perfect matches are not ranked out of Top-K by heuristics
                $nearMatches = array_values(array_filter($scored, fn($r) => !empty($r->__near_match)));
                if (!empty($nearMatches)) {
                    $present = [];
                    foreach ($topK as $r) { $present[(string)($r->id ?? '')] = true; }
                    $prepend = [];
                    foreach ($nearMatches as $nm) {
                        $id = (string) ($nm->id ?? '');
                        if ($id !== '' && empty($present[$id])) {
                            $nm->__near_override = true;
                            $prepend[] = $nm;
                            $present[$id] = true;
                        }
                    }
                    if (!empty($prepend)) {
                        $topK = array_merge($prepend, $topK);
                        $topK = array_slice($topK, 0, $topKSize);
                    }
                }

                // Tag soft-rejected observationally
                foreach ($topK as $r) {
                    $r->__soft_rejected = ((float) ($r->__composite ?? 0.0)) > $softScoreLimit;
                }

                // Stage 2: Apply preferences (normalized first within same KI, except story/example)
                $preferNormalized = !in_array($intent, ['story','example'], true);
                if ($preferNormalized) {
                    usort($topK, function ($a, $b) {
                        $cmp = ($a->__composite <=> $b->__composite);
                        if ($cmp !== 0) return $cmp;
                        $aKi = (string) ($a->knowledge_item_id ?? '');
                        $bKi = (string) ($b->knowledge_item_id ?? '');
                        if ($aKi !== '' && $aKi === $bKi) {
                            $aNorm = ((string) ($a->source_variant ?? 'raw')) === 'normalized';
                            $bNorm = ((string) ($b->source_variant ?? 'raw')) === 'normalized';
                            if ($aNorm !== $bNorm) { return $aNorm ? -1 : 1; }
                        }
                        return 0;
                    });
                }

                // Selection: Protected-first with excerpt cap handling (protected may bypass cap)
                $excerptCap = (int) (config('vector.retrieval.max_excerpt_chunks', 2));
                $excerptUsed = 0;
                $selected = [];
                // Partition protected vs non-protected
                $protected = array_values(array_filter($topK, fn($r) => !empty($r->__near_match)));
                $unprotected = array_values(array_filter($topK, fn($r) => empty($r->__near_match)));
                // Sort protected by raw distance
                usort($protected, function($a, $b) {
                    $da = (float) ($a->distance ?? $a->__distance ?? 1.0);
                    $db = (float) ($b->distance ?? $b->__distance ?? 1.0);
                    return $da <=> $db;
                });
                if (count($protected) > $limit) {
                    try {
                        Log::warning('retriever.protected_overflow', [
                            'query' => $query,
                            'protected_count' => count($protected),
                            'return_k' => $limit,
                        ]);
                    } catch (\Throwable $e) {}
                }
                // Include protected first; allow excerpt to bypass cap
                foreach ($protected as $row) {
                    if ((string) ($row->chunk_type ?? '') === 'excerpt') {
                        if ($excerptUsed >= $excerptCap) { $row->__excerpt_cap_bypassed = true; }
                        else { $excerptUsed++; }
                    }
                    $selected[] = $row;
                }
                // Fill remainder with unprotected honoring excerpt cap
                foreach ($unprotected as $row) {
                    if ((string) ($row->chunk_type ?? '') === 'excerpt') {
                        if ($excerptUsed >= $excerptCap) { continue; }
                        $excerptUsed++;
                    }
                    $selected[] = $row;
                }

                // Sparse-Document Minimum Recall Guarantee (SD-MRG)
                $sparseCfg = (array) config('ai.retriever.sparse_recall', []);
                $sparseEnabled = (bool) ($sparseCfg['enabled'] ?? false);
                if ($sparseEnabled && !empty($topKByDistance)) {
                    $threshold = (int) ($sparseCfg['chunk_threshold'] ?? 2);
                    $distanceCeiling = (float) ($sparseCfg['distance_ceiling'] ?? 0.20);
                    $maxInjections = max(0, (int) ($sparseCfg['max_injections'] ?? 1));

                    $kiIds = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki !== '') { $kiIds[$ki] = true; }
                    }
                    $chunkCounts = [];
                    if (!empty($kiIds)) {
                        $ids = array_keys($kiIds);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $countRows = DB::select("SELECT knowledge_item_id AS ki, COUNT(*) AS cnt FROM knowledge_chunks WHERE knowledge_item_id IN ($placeholders) AND is_active = true AND (usage_policy IS NULL OR usage_policy <> 'never_generate') GROUP BY knowledge_item_id", $ids);
                        foreach ($countRows as $cr) { $chunkCounts[(string) $cr->ki] = (int) $cr->cnt; }
                    }

                    $sparseCandidates = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki === '') { continue; }
                        $count = (int) ($chunkCounts[$ki] ?? 0);
                        $dist = (float) ($r->distance ?? $r->__distance ?? 1.0);
                        if ($count <= $threshold && $dist <= $distanceCeiling) {
                            $sparseCandidates[$ki][] = $r;
                        }
                    }

                    // Only block injection if a protected (near-match) candidate for this KI is already selected
                    $hasProtectedInSelected = function(array $arr, string $ki): bool {
                        foreach ($arr as $x) {
                            if ((string) ($x->knowledge_item_id ?? '') === $ki && !empty($x->__near_match)) {
                                return true;
                            }
                        }
                        return false;
                    };
                    $minByDistance = function(array $arr) { usort($arr, function($a, $b) { $da = (float) ($a->distance ?? $a->__distance ?? 1.0); $db = (float) ($b->distance ?? $b->__distance ?? 1.0); return $da <=> $db; }); return $arr[0] ?? null; };
                    $chooseBestForQuery = function(array $arr) use ($minByDistance, $query) {
                        $hintNumeric = (bool) preg_match('/(\$|\d|price|cost|amount|million|billion)/i', (string) $query);
                        if ($hintNumeric) {
                            $metrics = array_values(array_filter($arr, fn($x) => (string) ($x->chunk_role ?? '') === 'metric'));
                            if (!empty($metrics)) { return $minByDistance($metrics); }
                        }
                        return $minByDistance($arr);
                    };

                    $injected = 0;
                    foreach ($sparseCandidates as $ki => $chunksForKi) {
                        if ($injected >= $maxInjections) { break; }
                        if ($hasProtectedInSelected($selected, $ki)) { continue; }
                        $best = $chooseBestForQuery($chunksForKi);
                        if ($best === null) { continue; }
                        if ((string) ($best->chunk_type ?? '') === 'excerpt' && $excerptUsed >= $excerptCap) { continue; }
                        $best->__recall_injected = true;
                        if ((string) ($best->chunk_type ?? '') === 'excerpt') { $excerptUsed++; }
                        $selected[] = $best;
                        $injected++;
                        try {
                            Log::info('retriever.sparse_recall_injection', [
                                'knowledge_item_id' => (string) ($best->knowledge_item_id ?? ''),
                                'chunk_id' => (string) ($best->id ?? ''),
                                'distance' => (float) ($best->distance ?? $best->__distance ?? 0.0),
                                'reason' => 'sparse_doc_recall',
                            ]);
                        } catch (\Throwable) {}
                    }
                }

                // Small-Dense Assist (anti-crowding)
                $sdaCfg = (array) config('ai.retriever.small_dense_assist', []);
                $sdaEnabled = (bool) ($sdaCfg['enabled'] ?? false);
                if ($sdaEnabled && !empty($topKByDistance)) {
                    $minChunks = (int) ($sdaCfg['chunk_threshold_min'] ?? 3);
                    $maxChunks = (int) ($sdaCfg['chunk_threshold_max'] ?? 6);
                    $distanceCeiling = (float) ($sdaCfg['distance_ceiling'] ?? 0.15);
                    $maxInjections = max(0, (int) ($sdaCfg['max_injections'] ?? 1));

                    $kiIds = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki !== '') { $kiIds[$ki] = true; }
                    }
                    $chunkCounts = [];
                    if (!empty($kiIds)) {
                        $ids = array_keys($kiIds);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $countRows = DB::select("SELECT knowledge_item_id AS ki, COUNT(*) AS cnt FROM knowledge_chunks WHERE knowledge_item_id IN ($placeholders) AND is_active = true AND (usage_policy IS NULL OR usage_policy <> 'never_generate') GROUP BY knowledge_item_id", $ids);
                        foreach ($countRows as $cr) { $chunkCounts[(string) $cr->ki] = (int) $cr->cnt; }
                    }

                    $candidates = [];
                    foreach ($topKByDistance as $r) {
                        $ki = (string) ($r->knowledge_item_id ?? '');
                        if ($ki === '') { continue; }
                        $count = (int) ($chunkCounts[$ki] ?? 0);
                        $dist = (float) ($r->distance ?? $r->__distance ?? 1.0);
                        if ($count >= $minChunks && $count <= $maxChunks && $dist <= $distanceCeiling) {
                            $candidates[$ki][] = $r;
                        }
                    }

                    // Only block injection if a protected (near-match) candidate for this KI is already selected
                    $hasProtectedInSelected = function(array $arr, string $ki): bool {
                        foreach ($arr as $x) {
                            if ((string) ($x->knowledge_item_id ?? '') === $ki && !empty($x->__near_match)) {
                                return true;
                            }
                        }
                        return false;
                    };
                    $minByDistance = function(array $arr) {
                        usort($arr, function($a, $b) {
                            $da = (float) ($a->distance ?? $a->__distance ?? 1.0);
                            $db = (float) ($b->distance ?? $b->__distance ?? 1.0);
                            return $da <=> $db;
                        });
                        return $arr[0] ?? null;
                    };

                    $injected = 0;
                    foreach ($candidates as $ki => $chunksForKi) {
                        if ($injected >= $maxInjections) { break; }
                        // Allow injection even if KI is present, unless a protected candidate is already selected
                        if ($hasProtectedInSelected($selected, $ki)) { continue; }
                        $best = $minByDistance($chunksForKi);
                        if ($best === null) { continue; }
                        if ((string) ($best->chunk_type ?? '') === 'excerpt' && $excerptUsed >= $excerptCap) { continue; }
                        $best->__recall_injected = true;
                        $best->__assist_reason = 'small_dense_assist';
                        if ((string) ($best->chunk_type ?? '') === 'excerpt') { $excerptUsed++; }
                        $selected[] = $best;
                        $injected++;
                        try {
                            Log::info('retriever.small_dense_injection', [
                                'knowledge_item_id' => (string) ($best->knowledge_item_id ?? ''),
                                'chunk_id' => (string) ($best->id ?? ''),
                                'distance' => (float) ($best->distance ?? $best->__distance ?? 0.0),
                                'reason' => 'small_dense_assist',
                            ]);
                        } catch (\Throwable) {}
                    }
                }

                // Stable-unique by id then truncate to limit
                $seen = [];
                $selected = array_values(array_filter($selected, function ($r) use (&$seen) {
                    $id = (string) ($r->id ?? '');
                    if ($id === '' || isset($seen[$id])) { return false; }
                    $seen[$id] = true; return true;
                }));
                $selected = array_slice($selected, 0, $limit);

                // Hard invariant guard: protected must survive
                if (!empty($protected)) {
                    $present = array_filter($selected, fn($f) => !empty($f->__protected) || !empty($f->__near_match));
                    if (empty($present)) {
                        try {
                            Log::error('retriever.invariant_violation.protected_dropped', [
                                'query' => $query,
                                'protected_ids' => array_map(fn($p) => $p->id ?? null, $protected),
                            ]);
                        } catch (\Throwable $e) {}
                    }
                }

                $chunks = array_map(function ($r) {
                    return [
                        'id' => (string) $r->id,
                        'chunk_text' => (string) $r->chunk_text,
                        'chunk_type' => (string) $r->chunk_type,
                        'chunk_role' => (string) ($r->chunk_role ?? ''),
                        'authority' => (string) ($r->authority ?? ''),
                        'confidence' => (float) ($r->chunk_confidence ?? 0.0),
                        'time_horizon' => (string) ($r->time_horizon ?? ''),
                        'tags' => is_array($r->tags ?? null) ? $r->tags : [],
                        'recall_injected' => !empty($r->__recall_injected),
                        'near_match' => !empty($r->__near_match),
                        'excerpt_cap_bypassed' => !empty($r->__excerpt_cap_bypassed),
                    ];
                }, $selected);
                // Build a richer trace payload: topK and final views
                $mapTrace = function ($arr) use ($weights) {
                    return array_map(function ($r) use ($weights) {
                        return [
                            'id' => (string) ($r->id ?? ''),
                            'knowledge_item_id' => (string) ($r->knowledge_item_id ?? ''),
                            'distance' => (float) ($r->distance ?? $r->__distance ?? 0.0),
                            'authority' => (string) ($r->authority ?? ''),
                            'confidence' => (float) ($r->chunk_confidence ?? 0.0),
                            'time_horizon' => (string) ($r->time_horizon ?? ''),
                            'chunk_type' => (string) ($r->chunk_type ?? ''),
                            'chunk_role' => (string) ($r->chunk_role ?? ''),
                            'source_variant' => (string) ($r->source_variant ?? 'raw'),
                            'composite_score' => (float) ($r->__composite ?? 0.0),
                            'factors' => [
                                'distance' => (float) ($r->__distance ?? 0.0),
                                'authority_penalty' => (float) ($r->__authority_penalty ?? 0.0),
                                'confidence_penalty' => (float) ($r->__confidence_penalty ?? 0.0),
                                'time_penalty' => (float) ($r->__time_penalty ?? 0.0),
                                'weights' => [
                                    'distance' => (float) ($weights['distance'] ?? 0.6),
                                    'authority' => (float) ($weights['authority'] ?? 0.15),
                                    'confidence' => (float) ($weights['confidence'] ?? 0.15),
                                    'time_horizon' => (float) ($weights['time_horizon'] ?? 0.10),
                                ],
                            ],
                            'soft_rejected' => (bool) (!empty($r->__soft_rejected)),
                            'recall_injected' => (bool) (!empty($r->__recall_injected)),
                            'near_match' => (bool) (!empty($r->__near_match)),
                            'protected' => (bool) (!empty($r->__protected)),
                            'excerpt_cap_bypassed' => (bool) (!empty($r->__excerpt_cap_bypassed)),
                        ];
                    }, $arr);
                };
                $tracePayload = [
                    'topK' => $mapTrace($topK),
                    'final' => $mapTrace($selected),
                ];

                return ['chunks' => $chunks, 'trace' => $tracePayload];
            }
        } catch (\Throwable $e) {
            try { Log::warning('retriever.knowledgeChunksTrace.error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
        }

        // Fallback: return empty
        return ['chunks' => [], 'trace' => []];
    }

    private function classifyIntentDomainFunnel(string $query, string $fallbackIntent): array
    {
        $q = trim((string) $query);
        $fallbackIntent = trim($fallbackIntent) !== '' ? trim($fallbackIntent) : 'educational';

        $compiler = app(KnowledgeCompiler::class);

        // Cheap heuristic fallback (used when LLM fails or query is empty)
        $heuristic = function () use ($q, $fallbackIntent, $compiler): array {
            $t = strtolower($q);
            $intent = $fallbackIntent;
            if ($t !== '') {
                if (str_contains($t, 'contrarian') || str_contains($t, 'counter') || str_contains($t, 'against')) $intent = 'contrarian';
                elseif (str_contains($t, 'sell') || str_contains($t, 'persuade') || str_contains($t, 'pitch')) $intent = 'persuasive';
                elseif (str_contains($t, 'story')) $intent = 'story';
                elseif (str_contains($t, 'how') || str_contains($t, 'explain') || str_contains($t, 'why')) $intent = 'educational';
            }

            $domain = '';
            if (preg_match('/\b(seo|google|ranking|serp|keyword|backlink|search)\b/i', $q)) $domain = 'seo';
            elseif (preg_match('/\b(saas|mrr|arr|churn|retention|ltv|cac)\b/i', $q)) $domain = 'saas';
            elseif (preg_match('/\b(marketing|copy|headline|cta|funnel|landing)\b/i', $q)) $domain = 'content marketing';
            elseif (preg_match('/\b(moneti[sz]e|monetization|revenue|ads|affiliate|rpm|cpm)\b/i', $q)) $domain = 'monetization';
            elseif (preg_match('/\b(growth|acquisition|distribution|viral)\b/i', $q)) $domain = 'growth';
            elseif ($q !== '') $domain = 'business strategy';

            $domain = $compiler->normalizeDomain($domain);

            // Rough funnel stage inference
            $funnel = 'awareness';
            if (preg_match('/\b(price|pricing|buy|purchase|demo|trial|cta)\b/i', $q)) $funnel = 'decision';
            elseif (preg_match('/\b(compare|vs\.?|alternatives|pros|cons)\b/i', $q)) $funnel = 'consideration';

            return ['intent' => $intent, 'domain' => $domain, 'funnel_stage' => $funnel];
        };

        if ($q === '') {
            return $heuristic();
        }

        // LLM-based classification (best-effort)
        try {
            $system = "Classify the user's prompt for retrieval. Return STRICT JSON only.\n"
                . "Schema: {\"intent\":\"educational|persuasive|contrarian|story|emotional\",\"domain\":\"SEO|Content marketing|SaaS|Monetization|Growth|Business strategy\",\"funnel_stage\":\"awareness|consideration|decision\"}.\n"
                . "Pick the single best values. Do not add extra keys.";
            $user = json_encode(['prompt' => $q], JSON_UNESCAPED_UNICODE);
            $client = app(\App\Services\Ai\LLMClient::class);
            $res = $client->call('classify_retrieval_intent', $system, $user, 'retrieval_intent_v1', [
                'temperature' => 0,
                'model' => (string) config('ai.models.classification', ''),
            ]);
            $data = is_array($res) ? $res : [];
            $intent = (string) ($data['intent'] ?? $fallbackIntent);
            $domain = (string) ($data['domain'] ?? '');
            $funnel = (string) ($data['funnel_stage'] ?? 'awareness');

            $allowedIntents = ['educational','persuasive','contrarian','story','emotional'];
            if (!in_array($intent, $allowedIntents, true)) $intent = $fallbackIntent;

            // Domain is open-vocabulary; normalize to lowercase for stable matching.
            $domain = $compiler->normalizeDomain((string) $domain);
            if ($domain === '') {
                $domain = (string) ($heuristic()['domain'] ?? 'business strategy');
            }

            $allowedFunnels = ['awareness','consideration','decision'];
            if (!in_array($funnel, $allowedFunnels, true)) $funnel = 'awareness';

            return ['intent' => $intent, 'domain' => $domain, 'funnel_stage' => $funnel];
        } catch (\Throwable) {
            return $heuristic();
        }
    }

    private function expandRetrievalQuery(string $query, array $intentInfo): array
    {
        $q = trim((string) $query);
        $domain = (string) ($intentInfo['domain'] ?? '');
        $intent = (string) ($intentInfo['intent'] ?? '');

        $terms = [];
        if ($q !== '') {
            $terms[] = $q;
        }

        // Domain expansions
        $d = strtolower(trim($domain));
        $domainTerms = match ($d) {
            'seo' => ['SEO', 'rankings', 'Google ranking signals', 'search algorithm updates', 'content quality guidelines', 'SEO penalties'],
            'content marketing' => ['content marketing', 'copywriting', 'positioning', 'messaging', 'content quality'],
            'saas' => ['SaaS', 'MRR', 'churn', 'retention', 'activation', 'pricing'],
            'monetization' => ['monetization', 'revenue', 'ads', 'affiliate', 'pricing'],
            'growth' => ['growth', 'acquisition', 'retention', 'distribution'],
            'business strategy' => ['business strategy', 'tradeoffs', 'constraints'],
            default => array_values(array_filter([
                $d !== '' ? $d : null,
                'tradeoffs',
                'constraints',
            ])),
        };
        $terms = array_merge($terms, $domainTerms);

        // Intent boosters
        if ($intent === 'contrarian') {
            $terms[] = 'counterarguments';
            $terms[] = 'tradeoffs';
        } elseif ($intent === 'persuasive') {
            $terms[] = 'benefits';
            $terms[] = 'objections';
        } elseif ($intent === 'educational') {
            $terms[] = 'definitions';
            $terms[] = 'examples';
        }

        // De-dupe while preserving order
        $seen = [];
        $out = [];
        foreach ($terms as $t) {
            $t = trim((string) $t);
            if ($t === '') continue;
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $t;
        }

        // Keep expansion bounded; avoid smearing early-stage vector precision.
        return array_slice($out, 0, 6);
    }

    /**
     * Retrieve social research items (posts + research fragments) using embeddings when available.
     *
     * @return array<int,array<string,mixed>>
     */
    public function researchItems(
        string $organizationId,
        string $query,
        int $limit = 40,
        array $mediaTypes = ['post', 'research_fragment'],
        array $options = []
    ): array {
        $limit = max(1, min(100, $limit));
        $candidateLimit = (int) ($options['candidateLimit'] ?? config('ai.research.candidate_limit', 800));
        $candidateLimit = max($limit, min(5000, $candidateLimit));

        $query = trim((string) $query);
        $queryVector = [];
        if ($query !== '') {
            $queryVector = app(EmbeddingsService::class)->embedOne($query);
        }

        $prefix = config('social-watcher.table_prefix', 'sw_');
        $normalizedTable = $prefix . 'normalized_content';
        $embeddingsTable = $prefix . 'normalized_content_embeddings';
        $creativeTable = $prefix . 'creative_units';
        $hasOrg = Schema::hasColumn($normalizedTable, 'organization_id');

        $rows = DB::table($embeddingsTable . ' as e')
            ->join($normalizedTable . ' as n', 'n.id', '=', 'e.normalized_content_id')
            ->leftJoin($creativeTable . ' as cu', 'cu.normalized_content_id', '=', 'n.id')
            ->select([
                'n.id',
                'n.platform',
                'n.media_type',
                'n.media_type_detail',
                'n.text',
                'n.title',
                'n.url',
                'n.author_name',
                'n.author_username',
                'n.published_at',
                'n.likes',
                'n.comments',
                'n.shares',
                'n.views',
                'n.engagement_score',
                'n.raw_reference_id',
                'e.vector',
                'e.object_type',
                'cu.id as creative_unit_id',
                'cu.hook_text',
                'cu.angle',
                'cu.value_promises',
                'cu.proof_elements',
                'cu.offer',
                'cu.cta',
                'cu.hook_archetype',
                'cu.hook_novelty',
                'cu.emotional_drivers',
                'cu.audience_persona',
                'cu.sophistication_level',
            ])
            ->when(!empty($mediaTypes), function ($q) use ($mediaTypes) {
                $q->whereIn('n.media_type', $mediaTypes);
            })
            ->when($hasOrg, function ($q) use ($organizationId) {
                $q->where('n.organization_id', $organizationId);
            })
            ->orderByDesc('n.published_at')
            ->orderByDesc('n.created_at')
            ->limit($candidateLimit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $embedding = $this->decodeVector($row->vector ?? null);
            $similarity = (!empty($queryVector) && !empty($embedding))
                ? $this->cosineSimilarity($queryVector, $embedding)
                : 0.0;

            $items[] = array_merge($this->mapResearchRow($row), [
                'embedding' => $embedding,
                'similarity' => $similarity,
                'confidence_hint' => $similarity > 0 ? $similarity : null,
                'match_type' => $query !== '' ? 'embedding' : 'recent',
            ]);
        }

        if ($query !== '' && count($items) < $limit) {
            $remaining = $limit - count($items);
            $keywordItems = $this->keywordFallbackResearch($organizationId, $query, $mediaTypes, $remaining, $items);
            $items = array_merge($items, $keywordItems);
        }

        usort($items, function ($a, $b) {
            $sa = (float) ($a['similarity'] ?? 0.0);
            $sb = (float) ($b['similarity'] ?? 0.0);
            if ($sa === $sb) {
                $ea = (float) ($a['engagement_score'] ?? 0.0);
                $eb = (float) ($b['engagement_score'] ?? 0.0);
                return $eb <=> $ea;
            }
            return $sb <=> $sa;
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * Keyword fallback when embeddings are missing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function keywordFallbackResearch(
        string $organizationId,
        string $query,
        array $mediaTypes,
        int $limit,
        array $existingItems
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $prefix = config('social-watcher.table_prefix', 'sw_');
        $normalizedTable = $prefix . 'normalized_content';
        $creativeTable = $prefix . 'creative_units';
        $hasOrg = Schema::hasColumn($normalizedTable, 'organization_id');

        $existingIds = array_values(array_filter(array_map(fn($i) => $i['id'] ?? null, $existingItems)));
        $keywords = $this->extractKeywords($query);
        if (empty($keywords)) {
            return [];
        }

        $q = DB::table($normalizedTable . ' as n')
            ->leftJoin($creativeTable . ' as cu', 'cu.normalized_content_id', '=', 'n.id')
            ->select([
                'n.id',
                'n.platform',
                'n.media_type',
                'n.media_type_detail',
                'n.text',
                'n.title',
                'n.url',
                'n.author_name',
                'n.author_username',
                'n.published_at',
                'n.likes',
                'n.comments',
                'n.shares',
                'n.views',
                'n.engagement_score',
                'n.raw_reference_id',
                'cu.id as creative_unit_id',
                'cu.hook_text',
                'cu.angle',
                'cu.value_promises',
                'cu.proof_elements',
                'cu.offer',
                'cu.cta',
                'cu.hook_archetype',
                'cu.hook_novelty',
                'cu.emotional_drivers',
                'cu.audience_persona',
                'cu.sophistication_level',
            ])
            ->when(!empty($mediaTypes), function ($q) use ($mediaTypes) {
                $q->whereIn('n.media_type', $mediaTypes);
            })
            ->when($hasOrg, function ($q) use ($organizationId) {
                $q->where('n.organization_id', $organizationId);
            })
            ->when(!empty($existingIds), function ($q) use ($existingIds) {
                $q->whereNotIn('n.id', $existingIds);
            })
            ->where(function ($w) use ($keywords) {
                foreach ($keywords as $kw) {
                    $like = '%' . $kw . '%';
                    $w->orWhere('n.text', 'like', $like)
                        ->orWhere('n.title', 'like', $like);
                }
            })
            ->orderByDesc('n.engagement_score')
            ->orderByDesc('n.published_at')
            ->limit($limit);

        $rows = $q->get();
        $items = [];
        foreach ($rows as $row) {
            $items[] = array_merge($this->mapResearchRow($row), [
                'embedding' => null,
                'similarity' => 0.0,
                'confidence_hint' => null,
                'match_type' => 'keyword',
            ]);
        }

        return $items;
    }

    private function extractKeywords(string $query): array
    {
        $raw = preg_split('/\s+/', mb_strtolower($query)) ?: [];
        $keywords = array_values(array_filter(array_map(function ($word) {
            $w = trim(preg_replace('/[^\p{L}\p{N}_-]/u', '', $word));
            if (mb_strlen($w) < 3) {
                return null;
            }
            return $w;
        }, $raw)));
        return array_slice(array_unique($keywords), 0, 6);
    }

    private function mapResearchRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'platform' => (string) ($row->platform ?? ''),
            'source' => $this->mapSourceLabel((string) ($row->platform ?? '')),
            'media_type' => (string) ($row->media_type ?? ''),
            'media_type_detail' => (string) ($row->media_type_detail ?? ''),
            'text' => (string) ($row->text ?? ''),
            'title' => (string) ($row->title ?? ''),
            'url' => (string) ($row->url ?? ''),
            'author_name' => (string) ($row->author_name ?? ''),
            'author_username' => (string) ($row->author_username ?? ''),
            'published_at' => isset($row->published_at) ? (string) $row->published_at : null,
            'likes' => isset($row->likes) ? (int) $row->likes : null,
            'comments' => isset($row->comments) ? (int) $row->comments : null,
            'shares' => isset($row->shares) ? (int) $row->shares : null,
            'views' => isset($row->views) ? (int) $row->views : null,
            'engagement_score' => isset($row->engagement_score) ? (float) $row->engagement_score : null,
            'raw_reference_id' => isset($row->raw_reference_id) ? (string) $row->raw_reference_id : null,
            'creative' => [
                'creative_unit_id' => isset($row->creative_unit_id) ? (string) $row->creative_unit_id : null,
                'hook_text' => (string) ($row->hook_text ?? ''),
                'angle' => (string) ($row->angle ?? ''),
                'value_promises' => $this->normalizeJsonArray($row->value_promises ?? null),
                'proof_elements' => $this->normalizeJsonArray($row->proof_elements ?? null),
                'offer' => $row->offer ?? null,
                'cta' => $row->cta ?? null,
                'hook_archetype' => (string) ($row->hook_archetype ?? ''),
                'hook_novelty' => isset($row->hook_novelty) ? (float) $row->hook_novelty : null,
                'emotional_drivers' => $this->normalizeJsonArray($row->emotional_drivers ?? null),
                'audience_persona' => (string) ($row->audience_persona ?? ''),
                'sophistication_level' => (string) ($row->sophistication_level ?? ''),
            ],
        ];
    }

    private function mapSourceLabel(string $platform): string
    {
        $p = strtolower($platform);
        return match ($p) {
            'twitter', 'x' => 'x',
            'linkedin' => 'linkedin',
            'instagram' => 'instagram',
            'youtube' => 'youtube',
            default => 'other',
        };
    }

    private function decodeVector(?string $base64): ?array
    {
        if (!$base64) {
            return null;
        }
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return null;
        }
        $unpacked = unpack('f*', $binary);
        return $unpacked ? array_values($unpacked) : null;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        for ($i = 0; $i < $len; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }
        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function normalizeJsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
