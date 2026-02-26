# Social Watcher Restored Services - Quick Reference

## Usage Examples

### 1. Creative Annotation

```php
use LaundryOS\SocialWatcher\Services\CreativeIntelligence\CreativeAnnotationService;

$service = app(CreativeAnnotationService::class);

// Annotate a content node (post/video)
$annotation = $service->annotateContentNode($contentNode);

// Annotate a fragment (comment/transcript segment)
$annotation = $service->annotateFragment($fragment);

// Batch annotate
$annotations = $service->annotateBatchContentNodes($nodeIds);
```

**Result**: Annotation with 21 creative fields in payload

---

### 2. Topic Annotation

```php
use LaundryOS\SocialWatcher\Services\TopicIntelligence\TopicAnnotationService;

$service = app(TopicAnnotationService::class);

// Annotate a comment (with promotion gates)
$annotation = $service->annotateComment($comment);

// Batch annotate
$annotations = $service->annotateBatchComments($commentIds);

// Reprocess already-promoted comments
$annotations = $service->reprocessPromotedComments($limit = 100);
```

**Result**: Topic annotation with classification, signal_score, sentiment, gates

---

### 3. Clustering

```php
use LaundryOS\SocialWatcher\Services\AnnotationClusteringService;
use LaundryOS\SocialWatcher\Models\ContentAnnotation;

$service = app(AnnotationClusteringService::class);

// Creative clustering (format-grouped, fatigue detection)
$clusters = $service->clusterAnnotations(
    ContentAnnotation::TYPE_CREATIVE,
    $limit = 100
);

// Topic clustering (DBSCAN, topic scoring)
$clusters = $service->clusterAnnotations(
    ContentAnnotation::TYPE_TOPIC,
    $limit = 100
);

// Configure thresholds
$service->setDistanceThreshold(0.20)
    ->setMinClusterSize(5)
    ->clusterAnnotations(ContentAnnotation::TYPE_CREATIVE);
```

**Result**: AnnotationCluster models with metrics (fatigue_score, topic_score, etc.)

---

### 4. Content Brief Generation

```php
use LaundryOS\SocialWatcher\Services\CreativeIntelligence\ContentBriefGenerator;

$generator = app(ContentBriefGenerator::class);

// Generate from single cluster
$brief = $generator->generateFromTopicCluster($cluster);

// Batch generate from eligible clusters
$briefs = $generator->generateBatch($clusterIds);

// Find clusters that pass guardrails
$eligibleClusters = $generator->findEligibleClusters($limit = 10);

// Custom guardrails
$generator->setGuardrails([
    'min_distinct_commenters' => 5,
    'min_problem_items' => 3,
    'min_persistence_days' => 14,
    'min_topic_score' => 30.0,
])->generateFromTopicCluster($cluster);
```

**Result**: ContentBrief with hooks, angles, talking points, keywords

---

## Artisan Commands

### Test Pipeline
```bash
# Run full pipeline test
php artisan social-watcher:pipeline:test --mode=canonical

# Test specific profile
php artisan social-watcher:pipeline:test --profile=youtube_transcript --mode=canonical

# Skip ingestion (use existing data)
php artisan social-watcher:pipeline:test --skip-ingest --mode=canonical
```

---

## Data Model Relationships

```
ContentNode
    ├─ ContentAnnotation (creative) → 21 fields
    ├─ ContentAnnotation (topic) → classification, signal, sentiment, gates
    └─ Embedding → vector

AnnotationCluster
    ├─ member_ids → [annotation_ids]
    ├─ cluster_metrics → {fatigue, topic_score, cohesion, ...}
    └─ ContentBrief → hooks, angles, brief

ContentBrief
    ├─ source_cluster_id → AnnotationCluster
    └─ cluster_metrics → copied from cluster
```

---

## Payload Schemas

### Creative Annotation Payload
```json
{
  "hook_text": "How I automated 90% of my SEO workflow",
  "format_type": "thread",
  "angle": "automation_efficiency",
  "value_promises": ["save 20 hours/week", "zero coding required"],
  "proof_elements": ["case study", "screenshots"],
  "offer": {
    "type": "free_tool",
    "main_promise": "Free SEO automation template",
    "price_signals": ["free", "no credit card"],
    "friction_score": 0.2
  },
  "cta": {"type": "soft_ask", "text": "Link in bio", "urgency_level": "low"},
  "cta_type": "link_only",
  "audience_persona": "seo_agency_owner",
  "sophistication_level": "intermediate",
  "hook_archetype": "compression",
  "hook_intensity": 0.7,
  "hook_novelty": 0.8,
  "emotional_drivers": {"primary": "relief", "secondary": "greed", "intensity": 0.6},
  "tools_mentioned": ["Semrush", "Ahrefs", "ChatGPT"],
  "stack_type": "manual_plus_ai",
  "stack_complexity": "intermediate",
  "noise_risk": 0.1,
  "is_business_relevant": true,
  "buyer_quality_score": 0.8,
  "confidence": 0.9,
  "platform": "x",
  "author_username": "seo_guru",
  "published_at": "2026-01-14T10:30:00Z"
}
```

### Topic Annotation Payload
```json
{
  "classification": {"type": "problem", "confidence": 0.85},
  "signal_score": 12.5,
  "sentiment": -0.3,
  "question_intent": null,
  "engagement": {"likes": 10, "replies": 5, "reply_depth": 2},
  "promotion_eligible": true,
  "promotion_reason": "passed",
  "source_comment_id": "uuid",
  "platform": "youtube",
  "author_username": "user123",
  "published_at": "2026-01-14T10:30:00Z",
  "parent_post_id": "remote_id"
}
```

---

## Cluster Metrics

### Creative Cluster Metrics
```json
{
  "centroid": [0.123, 0.456, ...], // 1536 dimensions
  "cohesion": 0.15,
  "size": 12,
  "average_distance": 0.15,
  "fatigue_score": 0.35, // 0 = diverse, 1 = fatigued
  "format_type": "thread",
  "unique_hooks": 8,
  "unique_angles": 6
}
```

### Topic Cluster Metrics
```json
{
  "centroid": [0.123, 0.456, ...],
  "cohesion": 0.18,
  "size": 15,
  "average_distance": 0.18,
  "topic_score": 32.5, // Composite score
  "avg_signal_score": 10.2,
  "avg_sentiment": -0.25,
  "distinct_commenters": 8,
  "problem_count": 6
}
```

---

## Configuration

### Clustering Config
```php
// In your service provider or controller
$clusteringService->setDistanceThreshold(0.25); // Cosine distance (lower = more similar)
$clusteringService->setMinClusterSize(3); // Minimum items per cluster
```

### Brief Guardrails Config
```php
$generator->setGuardrails([
    'min_distinct_commenters' => 3,
    'min_problem_items' => 2,
    'min_persistence_days' => 7,
    'min_topic_score' => 25.0,
]);
```

---

## Debugging

### Check Annotation Status
```php
// Find annotations without clusters
$orphanAnnotations = ContentAnnotation::whereDoesntHave('clusters')->get();

// Check promotion stats
$promoted = Comment::where('is_promotable', true)->count();
$totalComments = Comment::count();
$promotionRate = $promoted / $totalComments;
```

### Check Cluster Quality
```php
// High fatigue clusters
$fatigued = AnnotationCluster::where('annotation_type', 'creative')
    ->whereRaw("cluster_metrics->>'fatigue_score' > 0.7")
    ->get();

// High-value topic clusters
$highValue = AnnotationCluster::where('annotation_type', 'topic')
    ->whereRaw("cluster_metrics->>'topic_score' > 30")
    ->get();
```

---

## Monitoring

### Key Metrics to Track
- Creative annotation success rate
- Topic annotation promotion rate (~5-15% expected)
- Cluster formation rate
- Brief generation success rate
- Average fatigue scores
- Average topic scores

### Logs to Monitor
- `creative_annotation_failed`
- `topic_annotation_failed_no_node`
- `comment_not_promotable`
- `content_brief_generation_error`

---

## Performance Tips

1. **Batch Operations**: Use batch methods for large datasets
   ```php
   $service->annotateBatchContentNodes($nodeIds); // Not in loop
   ```

2. **Limit Clustering**: Start with small batches
   ```php
   $clusters = $service->clusterAnnotations(TYPE, $limit = 50);
   ```

3. **Cache Embeddings**: Embeddings are expensive - reuse when possible

4. **Async Processing**: Queue large batch jobs
   ```php
   dispatch(new ClusterAnnotationsJob($type, $limit));
   ```

---

## Testing

### Unit Test Example
```php
public function test_creative_annotation_extracts_all_fields()
{
    $contentNode = ContentNode::factory()->create();
    $service = app(CreativeAnnotationService::class);
    
    $annotation = $service->annotateContentNode($contentNode);
    
    $this->assertNotNull($annotation);
    $this->assertArrayHasKey('hook_text', $annotation->payload);
    $this->assertArrayHasKey('cta_type', $annotation->payload);
    $this->assertArrayHasKey('buyer_quality_score', $annotation->payload);
}
```

---

## Troubleshooting

### Problem: Annotations not created
- Check LLM connectivity
- Verify content meets minimum requirements
- Check logs for extraction errors

### Problem: Topics not promoted
- Review promotion gates in logs
- Check signal_score calculation
- Verify classification is working

### Problem: No clusters forming
- Verify embeddings exist
- Check distance threshold (may be too strict)
- Ensure minimum cluster size is reasonable

### Problem: Briefs not generating
- Check cluster passes all guardrails
- Verify LLM connectivity
- Review cluster metrics (topic_score, etc.)

---

## Migration from Legacy

If you have legacy data:

1. **Keep legacy tables** (no need to drop)
2. **Run new pipeline** (writes to canonical tables)
3. **Compare outputs** (validation phase)
4. **Sunset legacy tables** (once validated)

No data loss. No downtime. Gradual migration supported.
