# Research Mode Canonical Social Watcher Integration

This document describes the implementation of canonical Social Watcher integration for research mode, replacing legacy normalized tables with the refactored canonical primitives.

## Overview

Research mode now supports two data readers:
- **Legacy**: Uses `sw_normalized_content`, `sw_normalized_content_fragments`, `sw_creative_units`, etc.
- **Canonical**: Uses `sw_content_nodes`, `sw_content_fragments`, `sw_content_annotations`, `sw_embeddings`, `sw_annotation_clusters`

The integration is controlled by a feature flag and designed for zero-disruption rollout.

## Architecture

### Components Created

#### 1. DTOs (Data Transfer Objects)

**`App\Services\Ai\Research\DTO\SocialEvidenceItem`**
- Unified evidence DTO for research mode
- Represents nodes, fragments, or annotations
- Provides `toArray()` for legacy compatibility
- Fields: id, kind, platform, contentType, url, title, text, authorUsername, publishedAt, metrics, source, debug

**`App\Services\Ai\Research\DTO\SocialClusterEvidence`**
- Cluster-based evidence with examples
- Used for hooks/angles/formats/topics
- Fields: clusterId, clusterType, label, summary, score, exampleItems, debug

#### 2. Services

**`App\Services\Ai\Research\Embeddings\ResearchQueryEmbeddingService`**
- Generates embeddings for research queries
- Implements 24-hour caching to reduce costs
- Supports batch embedding for efficiency
- Methods: `embed()`, `embedFresh()`, `embedBatch()`, `clearCache()`

**`App\Services\Ai\Research\Mappers\SocialWatcherEvidenceMapper`**
- Converts canonical SW models to evidence DTOs
- Centralizes mapping logic
- Supports batch operations
- Methods: `mapNode()`, `mapFragment()`, `mapAnnotation()`, `mapCluster()`, `mapEmbeddingResults()`

**`App\Services\Ai\Research\Sources\SocialWatcherResearchGateway`**
- **Main integration point** - single gateway for all canonical reads
- Replaces direct model access in research mode
- Responsibilities:
  - Semantic retrieval (fragments + nodes)
  - Cluster retrieval (hooks, angles, formats)
  - Conversation/context retrieval
  - Platform and time window filtering
- Methods:
  - `searchEvidence()` - semantic search with embeddings
  - `getClusters()` - retrieve typed clusters with examples
  - `getClusterConversation()` - get context for a cluster
  - `getRecentTrending()` - recent content for trend discovery

#### 3. Updated Services

**`App\Services\Ai\Research\ResearchExecutor`**
- Now injects `SocialWatcherResearchGateway`
- Updated `runDeepResearch()` and `runSaturationOpportunity()` to use gateway when canonical mode is enabled
- Logs reader mode for observability

**`App\Services\Ai\Research\HookGenerationService`**
- Fetches hook/angle/format clusters from canonical gateway
- Merges canonical clusters with CI recommendations
- Falls back to CI-only if canonical clusters unavailable

**`App\Services\Ai\Research\TrendDiscoveryService`**
- Uses `getRecentTrending()` for canonical trend discovery
- Implements `analyzeCanonicalTrends()` for processing canonical evidence
- Falls back to legacy path if canonical fails

#### 4. Configuration

**`config/research.php`** (new)
```php
'social_watcher_reader' => env('RESEARCH_SOCIAL_WATCHER_READER', 'legacy'),
'cluster_similarity' => env('RESEARCH_CLUSTER_SIMILARITY', 0.75),
'query_embedding_cache_ttl' => env('RESEARCH_QUERY_EMBEDDING_CACHE_TTL', 24),
```

#### 5. Verification Command

**`app/Console/Commands/Ai/VerifyCanonicalResearchCommand.php`**
- Tests research mode with canonical reader
- Detects legacy table access via query logging
- Confirms canonical table usage
- Usage:
  ```bash
  php artisan ai:research:verify-canonical --stage=deep_research --query="AI trends" --org=<org_id> --user=<user_id>
  ```

## Data Flow

### Deep Research (Canonical Path)

1. User submits query
2. `ResearchExecutor::runDeepResearch()` checks `config('research.social_watcher_reader')`
3. If `canonical`:
   - `ResearchQueryEmbeddingService::embed()` generates query vector (cached)
   - `SocialWatcherResearchGateway::searchEvidence()` performs semantic search:
     - Searches `sw_embeddings` with pgvector cosine similarity
     - Prefers `sw_content_fragments` (research_fragment) for detailed content
     - Falls back to `sw_content_nodes` (posts) when needed
     - Filters by org, platform, time window
   - `SocialWatcherEvidenceMapper` converts models to `SocialEvidenceItem[]`
   - DTOs converted to legacy array format via `toArray()` for compatibility
4. Rest of pipeline unchanged (clustering, LLM composition, snapshot)

### Angle/Hooks (Canonical Path)

1. `HookGenerationService::generate()` checks canonical flag
2. If enabled:
   - `SocialWatcherResearchGateway::getClusters('ci_hook')` retrieves hook clusters
   - `SocialWatcherResearchGateway::getClusters('ci_angle')` retrieves angle clusters
   - `SocialWatcherResearchGateway::getClusters('ci_format')` retrieves format clusters
   - Each cluster includes 3-6 representative examples (annotations)
3. Canonical clusters merged with CI recommendations
4. LLM prompt includes both canonical patterns and CI suggestions

### Trend Discovery (Canonical Path)

1. `TrendDiscoveryService::discover()` checks canonical flag
2. If enabled:
   - `SocialWatcherResearchGateway::getRecentTrending()` fetches recent nodes
   - `analyzeCanonicalTrends()` processes evidence items:
     - Extracts keywords from text
     - Buckets by recency (recent vs baseline)
     - Computes velocity ratios
3. Falls back to legacy table scan if canonical fails

### Saturation/Opportunity (Canonical Path)

1. Same as deep research retrieval
2. Uses canonical evidence for volume/fatigue/diversity analysis
3. Temporal comparison (recent vs baseline) works with `publishedAt` from nodes

## Rollout Plan

### Phase 1: Dark Launch (Current)
- Config: `RESEARCH_SOCIAL_WATCHER_READER=legacy`
- Canonical code deployed but not active
- No user impact

### Phase 2: Internal Verification
1. Ensure canonical pipeline is complete:
   - Nodes ingested with normalized columns
   - Fragments created for transcripts
   - Annotations typed (ci_hook, ci_angle, ci_format)
   - Embeddings generated for search
   - Clusters formed
2. Run verification:
   ```bash
   php artisan ai:research:verify-canonical --org=<test_org> --user=<test_user> --stage=deep_research --query="test query"
   ```
3. Check logs for legacy table access (should be none)
4. Validate research outputs are reasonable

### Phase 3: Limited Rollout
- Config: `RESEARCH_SOCIAL_WATCHER_READER=canonical`
- Monitor for 1-2 weeks
- Compare research quality/latency
- Rollback available via config change

### Phase 4: Full Rollout
- Default to canonical
- Remove legacy code paths (future)

## Migration Strategy

No data migration required. Research mode is read-only.

**Legacy tables NOT deleted:**
- `sw_normalized_content` - kept for backward compatibility
- `sw_creative_units` / `sw_creative_clusters` - kept if still used by other features
- Keywords, accounts, targets, content briefs - all kept as specified

**Hard cutover:** Once canonical is stable, legacy retrieval code can be removed from research services.

## Testing

### Manual Tests

```bash
# Deep research
php artisan ai:research:ask "AI content strategies" --stage=deep_research --org=<org> --user=<user> --platforms=x,linkedin

# Angle/hooks
php artisan ai:research:ask "fitness motivation posts" --stage=angle_hooks --org=<org> --user=<user>

# Trend discovery
php artisan ai:research:ask "AI" --stage=trend_discovery --org=<org> --user=<user> --industry=technology

# Saturation
php artisan ai:research:ask "personal branding" --stage=saturation_opportunity --org=<org> --user=<user>
```

### Verification

```bash
# Check for legacy table access
php artisan ai:research:verify-canonical --stage=deep_research --query="test" --org=<org> --user=<user>

# Expected output:
# ✓ Research execution completed
# ✓ No legacy Social Watcher tables accessed
# ✓ Canonical tables used: sw_content_nodes, sw_embeddings, sw_content_fragments
```

## Observability

### Logs

Canonical usage is logged:
```
research_retrieval_canonical: { evidence_count, reader: 'canonical' }
```

Legacy fallback logged as:
```
research_retrieval_error: { error, reader: 'canonical' }
```

### Snapshot Metadata

Research snapshots include:
- `reader: 'canonical'` in meta when used
- Canonical IDs in debug payloads (node_id, fragment_id, annotation_id, cluster_id, embedding_id)

### Debug Command

View prompts from snapshots:
```bash
php artisan ai:show-prompt <snapshot_id>
```

## Performance Considerations

### Query Caching
- Query embeddings cached for 24 hours
- Reduces OpenAI API calls significantly

### Vector Search
- Uses pgvector `<=>` operator for cosine similarity
- Requires indexes on `sw_embeddings.vector`
- Recommended index:
  ```sql
  CREATE INDEX idx_sw_embeddings_vector ON sw_embeddings USING ivfflat (vector vector_cosine_ops);
  ```

### Fragment Preference
- Fragments preferred over raw nodes for research
- Reduces noise (especially for YouTube transcripts)
- Matches legacy behavior of `sw_normalized_content_fragments`

## Troubleshooting

### No results from canonical path
- **Check:** Are nodes ingested? `SELECT COUNT(*) FROM sw_content_nodes WHERE org_id = ?`
- **Check:** Are embeddings created? `SELECT COUNT(*) FROM sw_embeddings WHERE purpose = 'search'`
- **Check:** Are fragments created? `SELECT COUNT(*) FROM sw_content_fragments`
- **Fallback:** Canonical failures are non-fatal; legacy path still works

### Legacy tables still accessed
- **Check:** Config value: `php artisan config:cache` and verify `config('research.social_watcher_reader')`
- **Check:** Other services might use legacy tables (generation mode, etc.)
- **Verify:** Use `ai:research:verify-canonical` command to isolate research mode

### Performance degradation
- **Check:** pgvector indexes present
- **Check:** Query embedding cache hit rate
- **Check:** Fragment count vs node count (fragments should be smaller subset)
- **Tune:** Adjust `ResearchOptions::limit` to reduce retrieval scope

## Future Enhancements

1. **Remove legacy code paths** once canonical is stable
2. **Add cluster quality metrics** to debug output
3. **Implement cluster-based saturation analysis** (currently uses individual items)
4. **Add conversation threading** via `parent_id` relationships in nodes
5. **Support multi-hop retrieval** (node → fragments → annotations)

## Related Documentation

- [Content Generator Service](../content-generator-service.md)
- [Social Watcher Usage Guide](../../SOCIAL_WATCHER_USAGE_GUIDE.md)
- [Research Chat Mode](../research-chat-mode.md)

## Contact

For questions or issues with canonical integration, check:
- Query logs for errors
- Snapshot metadata for reader mode confirmation
- Verification command output for table access analysis
