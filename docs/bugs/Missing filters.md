# Library Items API Specification

**Endpoint:** `GET /api/v1/library-items`

**Purpose:** Retrieve a filtered, sorted list of library items (bookmarks, text, files, drafts, posts, transcripts) with ingestion status information.

---

## Query Parameters

### Type Filtering
- **Parameter:** `type`
- **Type:** `string`
- **Valid Values:** `bookmark`, `text`, `file`, `draft`, `post`, `transcript`
- **Required:** No
- **Description:** Filter items by content type. When omitted, returns all types.
- **Example:** `?type=bookmark` returns only bookmark items
- **Frontend Behavior:** User clicks tabs at top of Library page (All Types, Bookmarks, Text, File, etc.)

### Ingestion Status Filtering
- **Parameter:** `ingestion_status`
- **Type:** `string`
- **Valid Values:** `completed`, `pending`, `failed`, `not_ingested`
- **Required:** No
- **Description:** Filter items by their AI knowledge base ingestion status.
- **Example:** `?ingestion_status=completed` returns only items that have been successfully ingested
- **Frontend Behavior:** User clicks status tabs (All Status, In Knowledge, Not Added, Processing, Failed)

**Status Value Mapping (Frontend → Backend):**
```
Frontend Value    → Backend Value
'ingested'        → 'completed'
'processing'      → 'pending'
'not_ingested'    → 'not_ingested'
'failed'          → 'failed'
```

### Folder Filtering
- **Parameter:** `folder_id`
- **Type:** `string` or special value
- **Valid Values:** Any valid folder UUID or `"null"` (string literal)
- **Required:** No
- **Description:** Filter items by folder. Use string `"null"` for unsorted items.
- **Example:** 
  - `?folder_id=abc-123-def` returns items in that folder
  - `?folder_id=null` returns items not in any folder (unsorted)
- **Frontend Behavior:** User selects folder from sidebar tree

### Tag Filtering
- **Parameter:** `tag_id`
- **Type:** `string`
- **Valid Values:** Any valid tag UUID
- **Required:** No
- **Description:** Filter items by tag. Frontend currently sends only one tag at a time.
- **Example:** `?tag_id=xyz-789`
- **Frontend Behavior:** User selects tag from sidebar

### Platform Filtering
- **Parameter:** `platform`
- **Type:** `string`
- **Valid Values:** `tiktok`, `instagram`, `youtube`, `twitter`, `reddit`
- **Required:** No
- **Description:** Filter items by source platform (typically applies to bookmarks)
- **Example:** `?platform=tiktok`

### Search Filtering
- **Parameter:** `search`
- **Type:** `string`
- **Required:** No
- **Description:** Full-text search across title, preview, and other relevant fields
- **Example:** `?search=marketing`
- **Frontend Behavior:** User types in global search bar

### Sorting
- **Parameter:** `sort`
- **Type:** `string`
- **Valid Values:** `created_at`, `updated_at`, `title`, `ingestion_status`
- **Required:** No
- **Default:** `created_at` (descending)
- **Description:** Sort order for results
- **Example:** `?sort=created_at`

### Pagination
- **Parameter:** `page`
- **Type:** `integer`
- **Required:** No
- **Default:** `1`

- **Parameter:** `per_page` or `limit`
- **Type:** `integer`
- **Required:** No
- **Default:** `20`

---

## Combined Example Requests

### Example 1: Get all bookmarks that haven't been ingested yet
```http
GET /api/v1/library-items?type=bookmark&ingestion_status=not_ingested
```

### Example 2: Get text items that are successfully ingested, sorted by creation date
```http
GET /api/v1/library-items?type=text&ingestion_status=completed&sort=created_at
```

### Example 3: Search for "marketing" across all types
```http
GET /api/v1/library-items?search=marketing
```

### Example 4: Get all items in a specific folder that are being processed
```http
GET /api/v1/library-items?folder_id=abc-123-def&ingestion_status=pending
```

### Example 5: Get unsorted TikTok bookmarks
```http
GET /api/v1/library-items?type=bookmark&platform=tiktok&folder_id=null
```

---

## Expected Response Format

### Response Structure
```json
{
  "data": [
    {
      "id": "123",
      "type": "bookmark",
      "title": "Amazing Marketing Strategy",
      "preview": "This is a preview of the content...",
      "thumbnail_url": "https://example.com/thumb.jpg",
      "source": "tiktok",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z",
      "bookmark": {
        "id": "123",
        "platform": "tiktok",
        "url": "https://tiktok.com/@user/video/123",
        "tags": [
          { "id": "tag1", "name": "Marketing", "color": "#ec4899" }
        ],
        "folder": {
          "id": "folder1",
          "name": "Research",
          "parent_id": null
        },
        "folder_id": "folder1"
      },
      "ingestion": {
        "status": "completed",
        "ingested_at": "2024-01-15T11:00:00Z",
        "confidence": 0.95,
        "error_message": null
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8
  }
}
```

### Field Descriptions

#### Root Level Fields (Required)
- `id`: Unique identifier for the library item
- `type`: Content type (`bookmark`, `text`, `file`, `draft`, `post`, `transcript`)
- `title`: Display title of the item
- `preview`: Text preview/excerpt of content (max ~200 chars recommended)
- `thumbnail_url`: URL to thumbnail image (can be null)
- `source`: Source platform or type (e.g., "tiktok", "manual", "upload")
- `created_at`: ISO 8601 timestamp
- `updated_at`: ISO 8601 timestamp

#### Bookmark Object (if type is 'bookmark')
- `bookmark.id`: Bookmark ID
- `bookmark.platform`: Platform name
- `bookmark.url`: Original URL
- `bookmark.tags`: Array of tag objects with `id`, `name`, `color`
- `bookmark.folder`: Folder object or null
- `bookmark.folder_id`: Folder ID or null

#### Ingestion Object (Required)
- `ingestion.status`: Current ingestion status (`completed`, `pending`, `failed`, `not_ingested`)
- `ingestion.ingested_at`: ISO 8601 timestamp when ingestion completed (null if not ingested)
- `ingestion.confidence`: Confidence score 0-1 (optional, can be null)
- `ingestion.error_message`: Error message if status is `failed` (null otherwise)

---

## Current Issue

**Problem:** Backend appears to be ignoring the filter parameters and returning all items regardless of `type` and `ingestion_status` filters.

**Observed Behavior:**
- Frontend sends: `GET /api/v1/library-items?type=bookmark&ingestion_status=not_ingested`
- Backend returns: 20 mixed items (bookmarks, text, files, etc.) with various ingestion statuses

**Expected Behavior:**
- Backend should return ONLY items where `type = 'bookmark'` AND `ingestion.status = 'not_ingested'`
- If no items match the filters, return empty array: `{ "data": [], "meta": {...} }`

**Action Required:**
1. Verify that query parameters are being received by the backend
2. Implement WHERE clauses for all filter parameters
3. Test each filter individually and in combination
4. Ensure proper SQL/ORM filtering is applied before returning results

---

## Filter Logic Requirements

### AND Logic (All filters must match)
When multiple filters are provided, they should be combined with AND logic:
```sql
WHERE type = 'bookmark' 
  AND ingestion_status = 'completed'
  AND folder_id = 'abc-123'
  AND (title LIKE '%search%' OR preview LIKE '%search%')
```

### NULL Folder Handling
When `folder_id=null` (string literal), filter for items with NULL folder_id:
```sql
WHERE folder_id IS NULL
```

### Omitted Parameters
When a parameter is not provided, do not filter on that field:
- Request: `?type=bookmark` → Only filter by type
- Request: `?ingestion_status=completed` → Only filter by status
- Request: `?type=bookmark&ingestion_status=completed` → Filter by both

---

## Testing Checklist

- [ ] Type filter works (`?type=bookmark` returns only bookmarks)
- [ ] Type filter works for each type (text, file, draft, post, transcript)
- [ ] Ingestion status filter works (`?ingestion_status=completed`)
- [ ] Status filter works for each status (pending, failed, not_ingested)
- [ ] Combined type + status filter works
- [ ] Folder filter works with UUID
- [ ] Folder filter works with `null` string for unsorted items
- [ ] Tag filter works
- [ ] Platform filter works
- [ ] Search filter works
- [ ] Sorting works (created_at, updated_at, title)
- [ ] Pagination works
- [ ] No filters returns all items (default behavior)
- [ ] Filters with no matches return empty array

---

## Frontend Implementation Reference

**Location:** `/lib/api.ts` → `listLibraryItems()` method
**Component:** `/components/Library.tsx`

The frontend constructs filters as follows:
```typescript
const params: any = {};

// Type filtering
if (filters?.type) {
  params.type = filters.type;  // 'bookmark', 'text', etc.
}

// Status filtering with mapping
if (filters?.ingestion_status && filters.ingestion_status !== 'all') {
  const statusMap = {
    'ingested': 'completed',
    'processing': 'pending',
    'not_ingested': 'not_ingested',
    'failed': 'failed'
  };
  params.ingestion_status = statusMap[filters.ingestion_status];
}

// Folder filtering
if (filters?.folder_id && filters.folder_id !== 'unsorted') {
  params.folder_id = filters.folder_id;
}
if (filters?.folder_id === 'unsorted') {
  params.folder_id = 'null';  // String literal
}

// Tag filtering
if (filters?.tags && filters.tags.length > 0) {
  params.tag_id = filters.tags[0];
}

// Platform filtering
if (filters?.platform) {
  params.platform = filters.platform;
}

// Search
if (filters?.search) {
  params.search = filters.search;
}

// Sort
if (filters?.sort) {
  params.sort = filters.sort;
}
```

---

## Questions for Backend Developer

1. Are query parameters being logged/received correctly?
2. Is there ORM/query builder logic applying these filters?
3. Are there any default scopes or filters that might interfere?
4. Is the `ingestion_status` field accessible at the query level?
5. Should pagination be applied before or after filtering?

---

**Document Version:** 1.0  
**Date:** 2025-01-02  
**Frontend Contact:** [Your Name]  
**Backend Repository:** [Repo URL if applicable]
