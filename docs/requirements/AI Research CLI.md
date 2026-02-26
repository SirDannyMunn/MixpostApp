# AI Research CLI (`ai:research`) – V1 Engineering Spec

**Status:** Implemented (V1)

---

## 1. Purpose

Provide a **fast, developer-only entry point** to the Research Chat Mode via a Laravel Artisan command. This enables:

* quick end-to-end testing
* inspection of retrieval quality
* validation of embeddings + clustering
* debugging without UI or HTTP overhead

This command is explicitly **not user-facing**.

---

## 2. Command Definition

```bash
php artisan ai:research "<question>" [options]
```

### Namespace

* `ai:research`

### Example

```bash
php artisan ai:research "What are people saying about SEO for SaaS in 2026?" \
  --limit=40 \
  --include-kb=false \
  --dump=report \
  --json
```

---

## 3. Options (V1)

| Option         | Type   | Default | Description                              |
| -------------- | ------ | ------- | ---------------------------------------- |
| `--limit`      | int    | 40      | Max retrieved items                      |
| `--include-kb` | bool   | false   | Include knowledge chunks                 |
| `--sources`    | string | auto    | Comma list (`post,research_fragment,ti`) |
| `--dump`       | string | none    | `raw`, `clusters`, `snapshot`, `report`  |
| `--json`       | bool   | false   | Output raw JSON                          |
| `--trace`      | bool   | false   | Verbose execution trace                  |

---

Notes:
- `--sources=ti` is accepted but ignored until Topic Intelligence retrieval is wired into Research Mode.

## 4. High-Level Flow

```
Artisan Command
  -> Resolve org/user context
  -> ContentGeneratorService (mode=research)
  -> Retrieval + Clustering
  -> ResearchReportComposer
  -> SnapshotPersister
  -> Snapshot content dump to storage
  -> Console Output
```

The CLI is a **thin wrapper** around the existing Research Mode.

---

## 5. Implementation Details

### 5.1 Command Class

```php
class AiResearchCommand extends Command
{
    protected $signature = 'ai:research {question} {--limit=40} {--include-kb=false} {--sources=} {--dump=} {--json} {--trace}';

    protected $description = 'Run a Research Mode query against Creative Intelligence data';
}
```

---

### 5.1.1 Org/User Resolution

The command resolves a local org/user by selecting the most recent organization membership. This keeps the CLI zero-config for local debugging. If no membership exists, it falls back to the first organization and first user record.

---

### 5.2 Request Construction

```php
$options = [
    'mode' => 'research',
    'retrieval_limit' => (int) $this->option('limit'),
    'include_kb' => (bool) $this->option('include-kb'),
    'research_media_types' => $this->parseSources(),
    'return_debug' => $this->option('trace'),
];
```

---

### 5.3 Service Invocation

```php
$response = app(ContentGeneratorService::class)->generate(
    $orgId,
    $userId,
    $question,
    'generic',
    $options
);
```

No new services are introduced.

---

## 6. Output Modes

### 6.1 Default (Human-Readable)

* Question
* Dominant claims
* Disagreements
* Saturated angles
* Emerging angles
* Sample excerpts

Formatted for terminal reading.

---

### 6.2 Dump Modes

| Dump       | Output                   |
| ---------- | ------------------------ |
| `raw`      | Retrieved items + scores |
| `clusters` | Cluster IDs + summaries  |
| `snapshot` | Snapshot ID + metadata   |
| `report`   | Final report JSON        |

### 6.3 Snapshot Content Dump

Every run writes the exact JSON string stored in the snapshot `content` field to:

```
storage/app/ai-research/snapshot-<snapshot_id>.json
```

---

## 7. Error Handling

* Invalid question → exit with error
* No retrieval results -> empty report, still snapshot
* LLM failure → show error + snapshot ID

No retries in V1.

---

## 8. Logging & Observability

* Logs under `ai.research`
* Snapshot ID always printed
* `--trace` prints step timing + hit counts

---

## 9. Security & Safety

* CLI only (local / admin use)
* No publishing
* No KB promotion
* Read-only DB access

---

## 10. Success Criteria

The command is successful if:

* developers can run research queries in <10s
* retrieval quality is inspectable
* no UI is required for debugging
* results match Research Mode behavior

---

## 11. Out of Scope (V1)

* batch research queries
* saving named research sessions
* diffing two questions
* trend analysis

---

## 12. Summary

The `ai:research` Artisan command provides a **low-friction, high-signal debugging surface** for Research Mode.

It reuses existing infrastructure, introduces no new risk, and materially improves development velocity.
