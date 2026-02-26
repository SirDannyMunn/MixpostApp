# Cross-Domain OAuth Authentication - Implementation

## Architecture

OAuth state is the **canonical transport** for context in cross-domain OAuth flows. This eliminates session coupling and enables stateless, horizontally-scalable deployments.

```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│ Frontend │───>│ Backend  │───>│ LinkedIn │───>│ Backend  │───> Frontend
│          │    │ (encode  │    │ (state   │    │ (decode  │     
│          │    │ state)   │    │ param)   │    │ state)   │     
└──────────┘    └──────────┘    └──────────┘    └──────────┘
                     │                               │
                     └─── state={encrypted_payload} ─┘
```

## State Payload Structure

```json
{
  "iss": "velocity-social-scheduler",
  "return_url": "https://velocity.app/accounts",
  "org_id": "uuid",
  "user_id": "uuid",
  "client": "web|figma|chrome_ext",
  "nonce": "random-32-chars",
  "iat": 1700000000,
  "exp": 1700000300
}
```

### Security Measures

- **Encrypted + Signed**: Uses Laravel `Crypt::encryptString()` with APP_KEY
- **Expiration**: 5-minute TTL enforced via `exp` claim
- **Allowlist**: `return_url` validated against domain allowlist
- **Issuer Validation**: `iss` must match expected value
- **Client Validation**: `client` must be one of: `web`, `figma`, `chrome_ext`
- **Nonce**: Random value prevents replay attacks

---

## Implementation Files

| File | Purpose |
|------|---------|
| [OAuthStateService.php](../app/Services/OAuthStateService.php) | Encode/decode encrypted state payloads |
| [SocialAccountController.php](../app/Http/Controllers/Api/V1/SocialAccountController.php) | Generate state and initiate OAuth |
| [CallbackSocialProviderController.php](../packages/mixpost/src/Http/Controllers/CallbackSocialProviderController.php) | Decode state and handle callback |
| [OAuthHandoffController.php](../app/Http/Controllers/Api/V1/OAuthHandoffController.php) | Handoff token exchange for Chrome extension |

### Provider OAuth Traits Updated

All providers now check for `$this->values['oauth_state']` and use it as the state parameter:

- LinkedIn: [ManagesOAuth.php](../packages/mixpost/src/SocialProviders/LinkedIn/Concerns/ManagesOAuth.php)
- YouTube: [ManagesOAuth.php](../packages/mixpost/src/SocialProviders/YouTube/Concerns/ManagesOAuth.php)
- TikTok: [ManagesOAuth.php](../packages/mixpost/src/SocialProviders/TikTok/Concerns/ManagesOAuth.php)
- Pinterest: [ManagesOAuth.php](../packages/mixpost/src/SocialProviders/Pinterest/Concerns/ManagesOAuth.php)
- Facebook: [ManagesFacebookOAuth.php](../packages/mixpost/src/SocialProviders/Meta/Concerns/ManagesFacebookOAuth.php)
- Instagram: [ManagesInstagramOAuth.php](../packages/mixpost/src/SocialProviders/Meta/Concerns/ManagesInstagramOAuth.php)
- Threads: [ManagesThreadsOAuth.php](../packages/mixpost/src/SocialProviders/Meta/Concerns/ManagesThreadsOAuth.php)
- Mastodon: [ManagesOAuth.php](../packages/mixpost/src/SocialProviders/Mastodon/Concerns/ManagesOAuth.php)

---

## Usage

### Web SPA / Figma

```typescript
// 1. Initiate OAuth with return URL
const response = await api.get('/social-accounts/connect/linkedin', {
  params: { 
    return_url: window.location.href,
    client: 'web' // or 'figma'
  }
});

// 2. Redirect to OAuth provider
window.location.href = response.data.auth_url;

// 3. Handle callback (on your return URL page)
const params = new URLSearchParams(window.location.search);

if (params.get('success') === 'true') {
  const accountId = params.get('account_id');
  const username = params.get('username');
  showToast(`Connected ${username}!`);
  refreshAccounts();
} else if (params.get('error')) {
  showToast(`Error: ${params.get('error_description')}`, 'error');
} else if (params.get('status') === 'entity_selection_required') {
  // Facebook Pages, etc. - need to select which page/account
  const entityToken = params.get('entity_token');
  showEntitySelectionDialog(entityToken);
}
```

### Chrome Extension

Chrome extensions can't reliably receive cookies, so they use a handoff token:

```typescript
// 1. Initiate OAuth with chrome_ext client
const response = await api.get('/social-accounts/connect/linkedin', {
  params: { 
    return_url: chrome.runtime.getURL('callback.html'),
    client: 'chrome_ext'
  }
});

// 2. Open OAuth in new tab
chrome.tabs.create({ url: response.data.auth_url });

// 3. In callback.html, exchange the handoff token
const params = new URLSearchParams(window.location.search);
const handoffToken = params.get('handoff_token');

if (handoffToken) {
  const result = await fetch('/api/v1/oauth/handoff', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: handoffToken })
  }).then(r => r.json());
  
  if (result.success) {
    // Account connected!
  } else {
    // Handle error
  }
}
```

### Entity Selection Flow (Facebook Pages, etc.)

```typescript
// When status === 'entity_selection_required'
async function showEntitySelectionDialog(entityToken: string) {
  // 1. Get available entities
  const { entities } = await api.get('/oauth/entities', {
    params: { entity_token: entityToken }
  });
  
  // 2. Show selection UI
  const selectedEntityId = await showEntityPicker(entities);
  
  // 3. Complete selection
  const result = await api.post('/oauth/entities/select', {
    entity_token: entityToken,
    entity_id: selectedEntityId
  });
  
  if (result.success) {
    refreshAccounts();
  }
}
```

---

## API Endpoints

### Initiate OAuth
```
GET /api/v1/social-accounts/connect/{platform}
  ?return_url=https://velocity.app/accounts
  &client=web

Response: { "auth_url": "https://linkedin.com/oauth/..." }
```

### Handoff Token Exchange (Chrome Extension)
```
POST /api/v1/oauth/handoff
Body: { "token": "64-char-random-token" }

Response: { "success": true, "platform": "linkedin", "account_id": "123" }
  or: { "error": "...", "error_description": "..." }
```

### Entity Selection
```
GET /api/v1/oauth/entities?entity_token=...
Response: { "platform": "facebook", "entities": [...], "entity_token": "..." }

POST /api/v1/oauth/entities/select
Body: { "entity_token": "...", "entity_id": "page-123" }
Response: { "success": true, "account_id": "456" }
```

---

## Callback Query Parameters

### Success
```
?success=true&platform=linkedin&account_id=123&username=johndoe
```

### Error
```
?error=access_denied&error_description=User%20denied%20access&platform=linkedin
```

### Entity Selection Required
```
?status=entity_selection_required&platform=facebook&entity_token=...
```

### Chrome Extension Handoff
```
?handoff_token=64-char-random-token
```

---

## Allowed Return URL Domains

Configured in `OAuthStateService.php`:

- `velocity.app`, `www.velocity.app`
- `localhost`, `127.0.0.1`
- `*.figma.site`, `*.figmaiframepreview.figma.site`
- `social-scheduler-dev.usewebmania.com`

Add domains via:
```php
app(OAuthStateService::class)->allowDomain('new-domain.com');
```

---

## What This Fixes

✅ **Lost context** - State carries all context through OAuth flow  
✅ **Multi-server deployments** - Stateless, no session coupling  
✅ **Session expiry mid-OAuth** - State is self-contained  
✅ **Frontend/backend coupling** - Clean separation  
✅ **Chrome extension support** - Handoff token pattern  
✅ **Entity selection** - Token-based flow for Facebook Pages, etc.
