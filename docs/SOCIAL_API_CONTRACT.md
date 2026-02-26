# Main App Social Media API Contract

**Base URL:** `/api/v1`  
**Authentication:** `Bearer` token via Laravel Sanctum  
**Header Required:** `Authorization: Bearer {token}`

---

## Table of Contents

1. [Authentication](#authentication)
2. [OAuth Handoff (Public)](#oauth-handoff-public)
3. [Social Accounts](#social-accounts)
4. [OAuth Entity Selection](#oauth-entity-selection)
5. [Scheduled Posts](#scheduled-posts)

---

## Authentication

All endpoints (except OAuth handoff) require Sanctum authentication and organization context.

**Required Headers:**
```
Authorization: Bearer {sanctum_token}
Content-Type: application/json
Accept: application/json
X-Organization-Id: {organization_uuid}
```

**Middleware Stack:**
- `auth:sanctum,api` - Authentication
- `organization` - Organization context resolution
- `billing.access` - Billing/paywall check

---

## OAuth Handoff (Public)

Public endpoint for Chrome extensions to exchange a one-time handoff token for OAuth result.

### Exchange Handoff Token

**Endpoint:** `POST /api/v1/oauth/handoff`  
**Auth Required:** No  
**Rate Limit:** 30 requests/minute

**Request Body:**
```json
{
  "token": "string (64 characters, required)"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "platform": "facebook",
  "account_id": 123,
  "account_uuid": "abc123-def456-ghi789",
  "username": "MyPage"
}
```

**Error Response (400):**
```json
{
  "error": "invalid_token",
  "error_description": "Token is invalid, expired, or already used"
}
```

---

## Social Accounts

Manage connected social media accounts for the organization.

### List Social Accounts

Get all connected social accounts for the current organization.

**Endpoint:** `GET /api/v1/social-accounts`  
**Auth Required:** Yes  
**Authorization:** `viewAny` policy on Account

**Success Response (200):**
```json
[
  {
    "id": 1,
    "uuid": "abc123-def456-ghi789",
    "platform": "facebook",
    "platform_user_id": "123456789",
    "username": "mypage",
    "display_name": "My Facebook Page",
    "avatar_url": "https://example.com/avatar.jpg",
    "is_authorized": true,
    "connected_by": 42,
    "connected_at": "2025-01-15T10:30:00.000000Z",
    "created_at": "2025-01-15T10:30:00.000000Z",
    "updated_at": "2025-01-15T10:30:00.000000Z"
  }
]
```

---

### Get OAuth Connect URL

Get the OAuth authorization URL for connecting a social platform.

**Endpoint:** `GET /api/v1/social-accounts/connect/{platform}`  
**Auth Required:** Yes  
**Authorization:** `create` policy on Account

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `platform` | string | Platform identifier (see supported platforms) |

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `return_url` | string | No | URL to redirect after OAuth completes |
| `client` | string | No | Client type: `web`, `figma`, `chrome_ext`. Default: `web` |

**Return URL Allowlist:**
- `velocity.app`
- `www.velocity.app`
- `localhost`
- `127.0.0.1`
- `figma.site`
- `figmaiframepreview.figma.site`
- `social-scheduler-dev.usewebmania.com`

**Success Response (200):**
```json
{
  "auth_url": "https://www.facebook.com/v18.0/dialog/oauth?client_id=...&redirect_uri=...&state=..."
}
```

**OAuth State Payload (encrypted in `state` parameter):**
```json
{
  "iss": "velocity-social-scheduler",
  "return_url": "https://velocity.app/callback",
  "org_id": "org-uuid-here",
  "user_id": "user-uuid-here",
  "client": "web",
  "nonce": "random-32-char-string",
  "iat": 1705312200,
  "exp": 1705312500
}
```

**Note:** State is valid for 5 minutes (300 seconds).

---

### Create Social Account

Manually create/update a social account (typically after OAuth callback).

**Endpoint:** `POST /api/v1/social-accounts`  
**Auth Required:** Yes  
**Authorization:** `create` policy on Account

**Request Body:**
```json
{
  "platform": "instagram",
  "platform_user_id": "123456789",
  "username": "myaccount",
  "display_name": "My Instagram Account",
  "avatar_url": "https://example.com/avatar.jpg",
  "access_token": "EAABsbCS1iZAIBAO...",
  "refresh_token": "EAABsbCS1iZAIBAO...",
  "token_expires_at": "2025-03-15T10:30:00Z",
  "scopes": ["publish_content", "read_insights"]
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| `platform` | required, in: instagram, tiktok, youtube, twitter, linkedin, facebook, pinterest |
| `platform_user_id` | required, string |
| `username` | required, string |
| `display_name` | optional, string |
| `avatar_url` | optional, url, max:2000 |
| `access_token` | required, string |
| `refresh_token` | optional, string |
| `token_expires_at` | optional, date |
| `scopes` | optional, array |

**Success Response (201):**
```json
{
  "id": 1,
  "uuid": "abc123-def456-ghi789",
  "platform": "instagram",
  "platform_user_id": "123456789",
  "username": "myaccount",
  "display_name": "My Instagram Account"
}
```

**Note:** Uses `updateOrCreate` - will update existing account if `organization_id`, `provider`, and `provider_id` match.

---

### Delete Social Account

**Endpoint:** `DELETE /api/v1/social-accounts/{id}`  
**Auth Required:** Yes  
**Authorization:** `delete` policy on Account

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Account ID |

**Success Response (204):** No Content

---

## OAuth Entity Selection

For providers like Facebook that require entity selection (Pages, Groups) after OAuth.

### Get Available Entities

Get list of available entities (pages, accounts) for selection.

**Endpoint:** `GET /api/v1/oauth/entities`  
**Auth Required:** Yes

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `entity_token` | string | Yes | Entity selection token (64 chars) |

**Success Response (200):**
```json
{
  "platform": "facebook",
  "entities": [
    {
      "id": "123456789",
      "name": "My Facebook Page",
      "username": "myfacebookpage",
      "image": "https://example.com/page-avatar.jpg",
      "data": {
        "category": "Business",
        "followers_count": 5000
      }
    },
    {
      "id": "987654321",
      "name": "Another Page",
      "username": "anotherpage",
      "image": "https://example.com/page2-avatar.jpg",
      "data": {}
    }
  ],
  "entity_token": "abc123..."
}
```

**Error Response (400):**
```json
{
  "error": "invalid_token",
  "error_description": "Entity selection token is invalid or expired"
}
```

---

### Select Entity

Complete entity selection and save as a connected account.

**Endpoint:** `POST /api/v1/oauth/entities/select`  
**Auth Required:** Yes

**Request Body:**
```json
{
  "entity_token": "abc123... (64 characters)",
  "entity_id": "123456789"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "platform": "facebook",
  "account_id": 123,
  "account_uuid": "abc123-def456-ghi789",
  "username": "myfacebookpage"
}
```

**Error Responses:**

- **400 Bad Request:**
```json
{
  "error": "invalid_token",
  "error_description": "Entity selection token is invalid, expired, or already used"
}
```

- **404 Not Found:**
```json
{
  "error": "entity_not_found",
  "error_description": "The selected entity was not found"
}
```

- **500 Internal Server Error:**
```json
{
  "error": "internal_error",
  "error_description": "An error occurred while selecting the entity"
}
```

---

## Scheduled Posts

Create and manage scheduled social media posts.

### List Scheduled Posts

Get paginated list of scheduled posts for the organization.

**Endpoint:** `GET /api/v1/scheduled-posts`  
**Auth Required:** Yes  
**Authorization:** `viewAny` policy on ScheduledPost

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | - | Filter by status |
| `from` | datetime | - | Filter posts scheduled after this date |
| `to` | datetime | - | Filter posts scheduled before this date |
| `sort` | string | `scheduled_for` | Sort field |
| `order` | string | `asc` | Sort order: `asc` or `desc` |
| `per_page` | int | 20 | Items per page |
| `page` | int | 1 | Page number |

**Success Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": "uuid-here",
      "organization_id": "org-uuid",
      "project_id": "project-uuid",
      "created_by": "user-uuid",
      "caption": "Check out our new product! 🚀 #launch",
      "media_urls": [
        "https://example.com/image1.jpg",
        "https://example.com/image2.jpg"
      ],
      "scheduled_for": "2025-01-20T14:30:00.000000Z",
      "timezone": "America/New_York",
      "status": "scheduled",
      "published_at": null,
      "error_message": null,
      "created_at": "2025-01-15T10:00:00.000000Z",
      "updated_at": "2025-01-15T10:00:00.000000Z",
      "accounts": [
        {
          "id": "spa-uuid",
          "scheduled_post_id": "post-uuid",
          "social_account_id": "account-uuid",
          "platform_config": {},
          "status": "pending",
          "platform_post_id": null,
          "published_at": null,
          "error_message": null
        }
      ],
      "creator": {
        "id": "user-uuid",
        "name": "John Doe"
      }
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 1,
  "last_page_url": "...",
  "links": [...],
  "next_page_url": null,
  "path": "...",
  "per_page": 20,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

---

### Create Scheduled Post

**Endpoint:** `POST /api/v1/scheduled-posts`  
**Auth Required:** Yes  
**Authorization:** `create` policy on ScheduledPost

**Request Body:**
```json
{
  "caption": "Check out our new product! 🚀 #launch",
  "scheduled_for": "2025-01-20T14:30:00Z",
  "timezone": "America/New_York",
  "account_ids": [1, 2, 3],
  "project_id": "project-uuid-optional"
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| `caption` | required, string |
| `scheduled_for` | required, date |
| `timezone` | required, string (valid timezone) |
| `account_ids` | optional, array, min:1 |
| `account_ids.*` | integer, exists:social_accounts,id |
| `project_id` | optional, exists:projects,id |

**Success Response (201):**
```json
{
  "id": "uuid-here",
  "organization_id": "org-uuid",
  "project_id": null,
  "created_by": "user-uuid",
  "caption": "Check out our new product! 🚀 #launch",
  "media_urls": [],
  "scheduled_for": "2025-01-20T14:30:00.000000Z",
  "timezone": "America/New_York",
  "status": "scheduled",
  "published_at": null,
  "error_message": null,
  "created_at": "2025-01-15T10:00:00.000000Z",
  "updated_at": "2025-01-15T10:00:00.000000Z",
  "accounts": [
    {
      "id": "spa-uuid",
      "scheduled_post_id": "post-uuid",
      "social_account_id": 1,
      "platform_config": null,
      "status": "pending",
      "platform_post_id": null,
      "published_at": null,
      "error_message": null
    }
  ]
}
```

**Error Response (422):**
```json
{
  "message": "One or more accounts not in organization"
}
```

---

### Get Scheduled Post

**Endpoint:** `GET /api/v1/scheduled-posts/{id}`  
**Auth Required:** Yes  
**Authorization:** `view` policy on ScheduledPost

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string (UUID) | Scheduled post ID |

**Success Response (200):**
```json
{
  "id": "uuid-here",
  "organization_id": "org-uuid",
  "project_id": null,
  "created_by": "user-uuid",
  "caption": "Check out our new product! 🚀 #launch",
  "media_urls": [],
  "scheduled_for": "2025-01-20T14:30:00.000000Z",
  "timezone": "America/New_York",
  "status": "scheduled",
  "published_at": null,
  "error_message": null,
  "accounts": [
    {
      "id": "spa-uuid",
      "scheduled_post_id": "post-uuid",
      "social_account_id": "account-uuid",
      "platform_config": {},
      "status": "pending",
      "platform_post_id": null,
      "published_at": null,
      "error_message": null,
      "social_account": {
        "id": "account-uuid",
        "platform": "instagram",
        "username": "myaccount",
        "display_name": "My Account",
        "avatar_url": "https://example.com/avatar.jpg"
      }
    }
  ],
  "creator": {
    "id": "user-uuid",
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

---

### Update Scheduled Post

**Endpoint:** `PUT /api/v1/scheduled-posts/{id}`  
**Alternative:** `PATCH /api/v1/scheduled-posts/{id}`  
**Auth Required:** Yes  
**Authorization:** `update` policy on ScheduledPost

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string (UUID) | Scheduled post ID |

**Request Body:**
```json
{
  "caption": "Updated caption! 🎉",
  "media_urls": ["https://example.com/new-image.jpg"],
  "scheduled_for": "2025-01-21T16:00:00Z",
  "timezone": "America/Los_Angeles",
  "status": "scheduled",
  "account_ids": [1, 4]
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| `caption` | optional, string |
| `media_urls` | optional, array |
| `media_urls.*` | url, max:2000 |
| `scheduled_for` | optional, date |
| `timezone` | optional, string |
| `status` | optional, in: scheduled, publishing, published, failed, cancelled |
| `account_ids` | optional, array, min:1 |
| `account_ids.*` | integer, exists:social_accounts,id |

**Success Response (200):**
```json
{
  "id": "uuid-here",
  "caption": "Updated caption! 🎉",
  "status": "scheduled",
  "accounts": [...]
}
```

**Note:** When `account_ids` is provided, it syncs the accounts:
- Removes accounts no longer in the list
- Adds new accounts from the list

---

### Cancel Scheduled Post

Cancel a scheduled post (changes status to `cancelled`).

**Endpoint:** `POST /api/v1/scheduled-posts/{id}/cancel`  
**Auth Required:** Yes  
**Authorization:** `cancel` policy on ScheduledPost

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string (UUID) | Scheduled post ID |

**Success Response (200):**
```json
{
  "id": "uuid-here",
  "status": "cancelled",
  ...
}
```

---

### Delete Scheduled Post

Permanently delete a scheduled post.

**Endpoint:** `DELETE /api/v1/scheduled-posts/{id}`  
**Auth Required:** Yes  
**Authorization:** `delete` policy on ScheduledPost

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string (UUID) | Scheduled post ID |

**Success Response (204):** No Content

---

### Publish Now

Immediately publish a scheduled post.

**Endpoint:** `POST /api/v1/scheduled-posts/{id}/publish-now`  
**Auth Required:** Yes  
**Authorization:** `update` policy on ScheduledPost

**Path Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string (UUID) | Scheduled post ID |

**Success Response (200):**
```json
{
  "id": "uuid-here",
  "status": "published",
  "published_at": "2025-01-15T12:00:00.000000Z",
  "accounts": [
    {
      "id": "spa-uuid",
      "status": "published",
      "published_at": "2025-01-15T12:00:00.000000Z",
      "error_message": null
    }
  ]
}
```

---

## Data Models

### Social Account

```json
{
  "id": "uuid",
  "organization_id": "uuid",
  "connected_by": "uuid",
  "platform": "instagram",
  "platform_user_id": "123456789",
  "username": "myaccount",
  "display_name": "My Account",
  "avatar_url": "https://example.com/avatar.jpg",
  "access_token": "encrypted",
  "refresh_token": "encrypted",
  "token_expires_at": "2025-03-15T10:30:00.000000Z",
  "is_active": true,
  "last_sync_at": "2025-01-15T10:00:00.000000Z",
  "scopes": ["publish_content", "read_insights"],
  "connected_at": "2025-01-15T10:30:00.000000Z",
  "created_at": "2025-01-15T10:30:00.000000Z",
  "updated_at": "2025-01-15T10:30:00.000000Z",
  "deleted_at": null
}
```

### Scheduled Post

```json
{
  "id": "uuid",
  "organization_id": "uuid",
  "project_id": "uuid | null",
  "created_by": "uuid",
  "caption": "Post content here",
  "media_urls": ["url1", "url2"],
  "scheduled_for": "2025-01-20T14:30:00.000000Z",
  "timezone": "America/New_York",
  "status": "scheduled | publishing | published | failed | cancelled",
  "published_at": "datetime | null",
  "error_message": "string | null",
  "created_at": "2025-01-15T10:00:00.000000Z",
  "updated_at": "2025-01-15T10:00:00.000000Z",
  "deleted_at": null
}
```

### Scheduled Post Account (Pivot)

```json
{
  "id": "uuid",
  "scheduled_post_id": "uuid",
  "social_account_id": "uuid",
  "platform_config": {},
  "status": "pending | publishing | published | failed",
  "platform_post_id": "string | null",
  "published_at": "datetime | null",
  "error_message": "string | null",
  "created_at": "2025-01-15T10:00:00.000000Z",
  "updated_at": "2025-01-15T10:00:00.000000Z"
}
```

---

## Supported Platforms

| Platform | Identifier | Notes |
|----------|------------|-------|
| Facebook | `facebook` | Supports Pages via entity selection |
| Instagram | `instagram` | Business/Creator accounts |
| Twitter/X | `twitter` | |
| LinkedIn | `linkedin` | Personal profiles and Company pages |
| TikTok | `tiktok` | |
| YouTube | `youtube` | |
| Pinterest | `pinterest` | |

---

## Post Status Flow

```
scheduled → publishing → published
                      ↘ failed

scheduled → cancelled
```

| Status | Description |
|--------|-------------|
| `scheduled` | Post is queued for future publishing |
| `publishing` | Post is currently being published |
| `published` | Post was successfully published |
| `failed` | Publishing failed (see error_message) |
| `cancelled` | Post was cancelled before publishing |

---

## Error Responses

### Validation Error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required."
    ]
  }
}
```

### Unauthorized (401)

```json
{
  "message": "Unauthenticated."
}
```

### Forbidden (403)

```json
{
  "message": "This action is unauthorized."
}
```

### Not Found (404)

```json
{
  "message": "No query results for model [App\\Models\\ScheduledPost]."
}
```

### Server Error (500)

```json
{
  "message": "Server Error"
}
```

---

## Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `POST /api/v1/oauth/handoff` | 30/minute |
| All other endpoints | Default application limits |
