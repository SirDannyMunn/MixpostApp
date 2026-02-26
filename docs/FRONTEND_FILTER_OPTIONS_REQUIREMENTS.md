# Frontend Requirements: Content Node Filter Options

## Overview

The `/api/social-watcher/content-nodes` endpoint now returns available filter options in the `meta` response. This enables dynamic filter dropdowns that show only values that exist in the organization's data, along with counts for each option.

---

## API Response Structure

### Endpoint
```
GET /api/social-watcher/content-nodes
```

### Response Shape
```typescript
interface ContentNodesResponse {
  success: boolean;
  data: ContentNode[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    filter_options: FilterOptions;  // NEW
  };
}

interface FilterOptions {
  platforms: FilterOption[];
  content_types: FilterOption[];
  enrichment_statuses: FilterOption[];
  authors: FilterOption[];
}

interface FilterOption {
  value: string;
  count: number;
}
```

### Example Response
```json
{
  "success": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 25,
    "total": 120,
    "filter_options": {
      "platforms": [
        {"value": "instagram", "count": 45},
        {"value": "x", "count": 30},
        {"value": "youtube", "count": 25},
        {"value": "linkedin", "count": 20}
      ],
      "content_types": [
        {"value": "post", "count": 80},
        {"value": "comment", "count": 30},
        {"value": "transcript", "count": 10}
      ],
      "enrichment_statuses": [
        {"value": "complete", "count": 100},
        {"value": "pending", "count": 15},
        {"value": "failed", "count": 5}
      ],
      "authors": [
        {"value": "elonmusk", "count": 50},
        {"value": "mkbhd", "count": 35},
        {"value": "naval", "count": 20}
      ]
    }
  }
}
```

---

## Implementation Requirements

### 1. Filter Dropdown Components

For each filter type, render a dropdown/select with options from `filter_options`:

| Filter | Query Param | Options Source |
|--------|-------------|----------------|
| Platform | `platform` | `meta.filter_options.platforms` |
| Content Type | `content_type` | `meta.filter_options.content_types` |
| Enrichment Status | `enrichment_status` | `meta.filter_options.enrichment_statuses` |
| Author | `author_username` | `meta.filter_options.authors` |

### 2. Display Format

Show the count next to each option:

```
Platform ▼
├─ All Platforms
├─ Instagram (45)
├─ X (30)
├─ YouTube (25)
└─ LinkedIn (20)
```

### 3. Empty State Handling

- If a filter array is empty, hide or disable that filter dropdown
- Example: If `enrichment_statuses` is `[]`, don't show the enrichment filter

### 4. Author Filter Special Handling

- Authors list is limited to top 100 by post count
- Consider implementing:
  - Searchable/autocomplete dropdown for authors
  - "Show more" or type-ahead if user needs to find an author not in top 100

### 5. Caching Behavior

- Filter options are cached for 5 minutes server-side
- Counts may not reflect real-time after content changes
- No action needed on frontend, just be aware counts are eventually consistent

---

## Filter Query Parameters

When user selects a filter, add to the API request:

```typescript
// Example: User selects platform="instagram" and content_type="post"
GET /api/social-watcher/content-nodes?platform=instagram&content_type=post
```

### All Available Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Text search |
| `platform` | string | Filter by platform value |
| `content_type` | string | Filter by content type value |
| `enrichment_status` | string | Filter by enrichment status |
| `author_username` | string | Filter by author username |
| `source_id` | UUID | Filter by source ID |
| `has_annotations` | boolean | Filter to nodes with/without annotations |
| `published_after` | ISO8601 | Filter by publish date start |
| `published_before` | ISO8601 | Filter by publish date end |
| `order_by` | string | Sort field (default: `published_at`) |
| `order_dir` | `asc` \| `desc` | Sort direction (default: `desc`) |
| `per_page` | number | Page size (max: 100, default: 25) |
| `page` | number | Page number |

---

## TypeScript Types

```typescript
// Filter option from API
interface FilterOption {
  value: string;
  count: number;
}

// All filter options
interface FilterOptions {
  platforms: FilterOption[];
  content_types: FilterOption[];
  enrichment_statuses: FilterOption[];
  authors: FilterOption[];
}

// Current filter state
interface ContentNodeFilters {
  q?: string;
  platform?: string;
  content_type?: string;
  enrichment_status?: string;
  author_username?: string;
  source_id?: string;
  has_annotations?: boolean;
  published_after?: string;
  published_before?: string;
  order_by?: string;
  order_dir?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}
```

---

## UI/UX Recommendations

1. **Filter bar layout**: Horizontal bar with dropdowns for Platform, Content Type, Status, Author
2. **Clear filters button**: Reset all filters to default state
3. **Active filter pills**: Show selected filters as removable chips/pills
4. **Persist filters**: Store filter state in URL query params for shareable links
5. **Loading state**: Show skeleton/spinner while fetching with new filters

---

## Example React Implementation

```tsx
// Simplified example
function ContentNodeFilters({ filterOptions, onFilterChange, currentFilters }) {
  return (
    <div className="flex gap-4">
      <Select
        label="Platform"
        value={currentFilters.platform || ''}
        onChange={(v) => onFilterChange({ ...currentFilters, platform: v || undefined })}
      >
        <Option value="">All Platforms</Option>
        {filterOptions.platforms.map((opt) => (
          <Option key={opt.value} value={opt.value}>
            {opt.value} ({opt.count})
          </Option>
        ))}
      </Select>

      <Select
        label="Content Type"
        value={currentFilters.content_type || ''}
        onChange={(v) => onFilterChange({ ...currentFilters, content_type: v || undefined })}
      >
        <Option value="">All Types</Option>
        {filterOptions.content_types.map((opt) => (
          <Option key={opt.value} value={opt.value}>
            {opt.value} ({opt.count})
          </Option>
        ))}
      </Select>

      {/* Similar for enrichment_statuses and authors */}
    </div>
  );
}
```

---

## Notes

- Filter options reflect **only values that exist** in the organization's data
- Empty filters (no matching content) will have empty arrays
- Authors are sorted by count descending (most posts first)
- Other filters are sorted alphabetically by value
