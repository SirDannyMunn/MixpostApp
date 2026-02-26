# Frontend Requirements: Social Account Connection

## Overview
This document outlines the frontend requirements for implementing social media account connection functionality using the Mixpost package. The flow uses OAuth 2.0 for authentication with various social platforms (Twitter, Facebook, Instagram, LinkedIn, TikTok, YouTube, Pinterest).

---

## Architecture Overview

### OAuth Flow Pattern
```
[Frontend] → [Backend API] → [Social Platform OAuth] → [Callback Handler] → [Frontend Success]
```

1. User clicks "Connect [Platform]" button
2. Frontend calls API to initiate OAuth
3. Backend returns OAuth URL
4. User is redirected to social platform
5. After authorization, platform redirects to backend callback
6. Backend processes tokens and creates account record
7. User is redirected back to frontend
8. Frontend refreshes account list

---

## API Endpoints

### Base URL
```
/api/v1 (for REST API)
/mixpost (for Mixpost package routes)
```

### Available Endpoints

#### 1. List Social Accounts
```http
GET /api/v1/social-accounts
Authorization: Bearer {token}
X-Organization-ID: {organization_id}
```

**Response:**
```json
{
  "data": [
    {
      "id": "uuid",
      "platform": "twitter",
      "username": "acmecorp",
      "display_name": "ACME Corp",
      "avatar_url": "https://...",
      "is_active": true,
      "last_sync_at": "2025-01-15T10:00:00Z",
      "connected_at": "2025-01-01T00:00:00Z",
      "platform_user_id": "123456789",
      "scopes": ["read", "write"]
    }
  ]
}
```

**Note:** `access_token` and `refresh_token` are never returned in API responses.

---

#### 2. Initiate OAuth Connection (SPA-Friendly API Endpoint)
```http
GET /api/v1/social-accounts/connect/{platform}
Authorization: Bearer {token}
X-Organization-ID: {organization_id}
Accept: application/json
```

**Supported Platforms:**
- `twitter`
- `facebook`
- `instagram` (via Facebook)
- `linkedin`
- `tiktok`
- `youtube`
- `pinterest`
- `mastodon` (requires server URL)

**Response:** `200 OK`
```json
{
  "auth_url": "https://www.facebook.com/v18.0/dialog/oauth?client_id=...&redirect_uri=..."
}
```

**Frontend Usage:**
```typescript
// 1. Make AJAX request to get OAuth URL
const response = await fetch('/api/v1/social-accounts/connect/twitter', {
  headers: {
    'Accept': 'application/json',
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': orgId
  }
});

const { auth_url } = await response.json();

// 2. Redirect to OAuth URL
window.location.href = auth_url;
```

---

#### 3. OAuth Callback (Handled by Backend)
```http
GET /mixpost/callback/{platform}?code={auth_code}&state={state}
```

**Flow:**
- User authorizes on social platform
- Platform redirects to this callback URL
- Backend exchanges code for access token
- Backend creates/updates social account record
- Backend redirects to frontend accounts page

**Redirect Destinations:**
- Success: `/mixpost/accounts` (or your frontend route)
- Error: `/mixpost/accounts?error={error_message}`
- Multi-entity accounts (e.g., Facebook Pages): `/mixpost/accounts/entities/{platform}`

---

#### 4. Delete Social Account
```http
DELETE /api/v1/social-accounts/{id}
Authorization: Bearer {token}
```

**Response:**
```http
204 No Content
```

---

#### 5. Sync Social Account (Optional)
```http
POST /api/v1/social-accounts/{id}/sync
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "synced",
  "last_sync_at": "2025-01-15T16:00:00Z"
}
```

---

## Frontend Implementation Requirements

### 1. Social Accounts Page/Component

#### UI Components Needed

**Account List View:**
```tsx
interface SocialAccountCardProps {
  account: {
    id: string;
    platform: 'twitter' | 'facebook' | 'instagram' | 'linkedin' | 'tiktok' | 'youtube' | 'pinterest';
    username: string;
    display_name: string;
    avatar_url?: string;
    is_active: boolean;
    last_sync_at?: string;
    connected_at: string;
  };
  onDisconnect: (id: string) => void;
  onSync?: (id: string) => void;
}
```

**Connect Button:**
```tsx
interface ConnectButtonProps {
  platform: string;
  onConnect: (platform: string) => void;
  loading?: boolean;
}
```

---

### 2. State Management

**Required State:**
```typescript
interface SocialAccountsState {
  accounts: SocialAccount[];
  loading: boolean;
  error: string | null;
  connectingPlatform: string | null;
}
```

---

### 3. API Service Layer

```typescript
// services/socialAccounts.ts

export const socialAccountsService = {
  /**
   * Fetch all social accounts for current organization
   */
  async fetchAccounts(): Promise<SocialAccount[]> {
    const response = await fetch('/api/v1/social-accounts', {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${getAuthToken()}`,
        'X-Organization-ID': getCurrentOrgId(),
      }
    });
    
    if (!response.ok) throw new Error('Failed to fetch accounts');
    
    const data = await response.json();
    return data.data || data;
  },

  /**
   * Initiate OAuth flow for a platform
   * Makes AJAX request to get OAuth URL, then redirects
   */
  async connectAccount(platform: string): Promise<void> {
    // Step 1: Get OAuth URL via AJAX
    const response = await fetch(`/api/v1/social-accounts/connect/${platform}`, {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${getAuthToken()}`,
        'X-Organization-ID': getCurrentOrgId(),
      }
    });
    
    if (!response.ok) throw new Error('Failed to initiate connection');
    
    const { auth_url } = await response.json();
    
    // Step 2: Redirect to OAuth provider
    window.location.href = auth_url;
  },

  /**
   * Delete a social account
   */
  async deleteAccount(accountId: string): Promise<void> {
    const response = await fetch(`/api/v1/social-accounts/${accountId}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${getAuthToken()}`,
        'X-Organization-ID': getCurrentOrgId(),
      }
    });
    
    if (!response.ok) throw new Error('Failed to delete account');
  },

  /**
   * Sync account data with platform
   */
  async syncAccount(accountId: string): Promise<void> {
    const response = await fetch(`/api/v1/social-accounts/${accountId}/sync`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${getAuthToken()}`,
        'X-Organization-ID': getCurrentOrgId(),
      }
    });
    
    if (!response.ok) throw new Error('Failed to sync account');
  }
};
```

---

### 4. React Component Example

```tsx
import { useState, useEffect } from 'react';
import { socialAccountsService } from './services/socialAccounts';

export function SocialAccountsPage() {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Load accounts on mount and after returning from OAuth
  useEffect(() => {
    loadAccounts();
    
    // Check for OAuth callback errors
    const params = new URLSearchParams(window.location.search);
    const errorParam = params.get('error');
    if (errorParam) {
      setError(decodeURIComponent(errorParam));
      // Clean up URL
      window.history.replaceState({}, '', window.location.pathname);
    }
  }, []);

  async function loadAccounts() {
    try {
      setLoading(true);
      setError(null);
      const data = await socialAccountsService.fetchAccounts();
      setAccounts(data);
    } catch (err) {
      setError('Failed to load social accounts');
      console.error(err);
    } finally {
      setLoading(false);
    }
  }

  async function handleConnect(platform: string) {
    try {
      // This will redirect to OAuth flow
      await socialAccountsService.connectAccount(platform);
    } catch (err) {
      setError(`Failed to connect ${platform}`);
      console.error(err);
    }
  }

  async function handleDisconnect(accountId: string) {
    if (!confirm('Are you sure you want to disconnect this account?')) {
      return;
    }

    try {
      await socialAccountsService.deleteAccount(accountId);
      await loadAccounts(); // Refresh list
    } catch (err) {
      setError('Failed to disconnect account');
      console.error(err);
    }
  }

  async function handleSync(accountId: string) {
    try {
      await socialAccountsService.syncAccount(accountId);
      await loadAccounts(); // Refresh list
    } catch (err) {
      setError('Failed to sync account');
      console.error(err);
    }
  }

  if (loading) return <LoadingSpinner />;

  return (
    <div className="social-accounts-page">
      <h1>Connected Social Accounts</h1>
      
      {error && (
        <Alert type="error" onDismiss={() => setError(null)}>
          {error}
        </Alert>
      )}

      <div className="accounts-grid">
        {accounts.map(account => (
          <SocialAccountCard
            key={account.id}
            account={account}
            onDisconnect={handleDisconnect}
            onSync={handleSync}
          />
        ))}
      </div>

      <div className="connect-platforms">
        <h2>Connect New Platform</h2>
        <div className="platform-buttons">
          {SUPPORTED_PLATFORMS.map(platform => {
            const isConnected = accounts.some(a => a.platform === platform);
            return (
              <button
                key={platform}
                onClick={() => handleConnect(platform)}
                disabled={isConnected}
                className="platform-button"
              >
                <PlatformIcon platform={platform} />
                {isConnected ? 'Connected' : `Connect ${platform}`}
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}

const SUPPORTED_PLATFORMS = [
  'twitter',
  'facebook',
  'instagram',
  'linkedin',
  'tiktok',
  'youtube',
  'pinterest'
];
```

---

### 5. Social Account Card Component

```tsx
interface SocialAccountCardProps {
  account: SocialAccount;
  onDisconnect: (id: string) => void;
  onSync: (id: string) => void;
}

export function SocialAccountCard({ account, onDisconnect, onSync }: SocialAccountCardProps) {
  return (
    <div className="social-account-card">
      <div className="account-header">
        <img 
          src={account.avatar_url || getDefaultAvatar(account.platform)} 
          alt={account.display_name}
          className="avatar"
        />
        <div className="account-info">
          <h3>{account.display_name}</h3>
          <p className="username">@{account.username}</p>
          <span className={`platform-badge ${account.platform}`}>
            {account.platform}
          </span>
        </div>
      </div>

      <div className="account-meta">
        <div className="meta-item">
          <span className="label">Status:</span>
          <span className={`status ${account.is_active ? 'active' : 'inactive'}`}>
            {account.is_active ? 'Active' : 'Inactive'}
          </span>
        </div>
        <div className="meta-item">
          <span className="label">Connected:</span>
          <span>{formatDate(account.connected_at)}</span>
        </div>
        {account.last_sync_at && (
          <div className="meta-item">
            <span className="label">Last Sync:</span>
            <span>{formatRelativeTime(account.last_sync_at)}</span>
          </div>
        )}
      </div>

      <div className="account-actions">
        <button onClick={() => onSync(account.id)} className="btn-secondary">
          <RefreshIcon /> Sync
        </button>
        <AJAX First, Redirect Second**
- ⚠️ **Do NOT navigate directly** to the OAuth endpoint
- ✅ **Use fetch/axios** to get the OAuth URL as JSON
- ✅ **Then redirect** to that URL

**❌ Wrong (causes document request):**
```typescript
// Don't do this!
window.location.href = '/api/v1/social-accounts/connect/twitter';
```

**✅ Correct (AJAX request first):**
```typescript
// Do this!
const response = await fetch('/api/v1/social-accounts/connect/twitter', {
  headers: { 'Accept': 'application/json', ... }
});
const { auth_url } = await response.json();
wind3w.location.href = auth_url; // Now redirect
```

### 2. **Session Management**
- The Mixpost OAuth callback requires **session cookies**
- Ensure your app maintains Laravel session cookies
- Session is used during callback to verify state
    </div>
  );
}
```

---

## Important Implementation Notes

### 1. **Session Management**
- The Mixpost OAuth flow requires **session cookies** (not just JWT tokens)
- Ensure your app maintains Laravel session cookies
- Use `credentials: 'include'` in fetch requests if needed

### 4. **Redirect Handling**
```typescript
// After OAuth, user returns to your app
// Check URL for success/error messages
useEffect(() => {
  const params = new URLSearchParams(window.location.search);
  
  if (params.has('error')) {
    showError(params.get('error'));
  } else if (params.has('success')) {
    showSuccess('Account connected successfully!');
  }
  
  // Clean URL
  wi5dow.history.replaceState({}, '', window.location.pathname);
  
  // Refresh accounts list
  loadAccounts();
}, []);
```

### 3. **Multi-Entity Accounts (Facebook, LinkedIn)**
Some platforms allow selecting specific pages/entities:
- Facebook: Can connect multiple Pages from one account
- LinkedIn: Can connect company pages

**Flow:**
1. User authorizes on platform
2. Backend redirects to `/mixpost/accounts/entities/{platform}`
3. Frontend shows entity selection page
4. User selects which pages/entities to connect
5. POST to `/mixpost/accounts/entities/{platform}` with selections

---

### 4. **Mastodon Special Case**
Mastodon requires a server URL:
```typescript
async function connectMastodon(serverUrl: string) {
  // First, create app on the Mastodon server
  await fetch('/mixpost/services/create-mastodon-app', {
    method: 'POST',
    body: JSON.stringify({ server: serverUrl })
  });
  
  // Then proceed with normal OAuth flow
  await socialAccountsService.connectAccount('mastodon');
}
```

---

### 6. **Error Handling**

**Common Errors:**
- `403`: User denied permission
- `400`: Invalid OAuth callback (missing code/state)
- `422`: Invalid platform or configuration
- `500`: Platform API error

**Handle gracefully:**
```typescript
try {
  await socialAccountsService.connectAccount(platform);
} catch (error) {
  if (error.status === 403) {
    showError('You denied permission. Please try again and grant access.');
  } else if (error.status === 422) {
    showError('This platform is not properly configured. Contact support.');
  } else {
    showError('Failed to connect account. Please try again.');
  }
}
```

---

## UI/UX Requirements

### 1. **Loading States**
- Show loading spinner while fetching accounts
- Show "Connecting..." state when initiating OAuth
- Disable buttons while operations are in progress

### 2. **Success Feedback**
- Show success toast/notification after connecting
- Animate new account card appearing in list
- Auto-refresh account list after connection

### 3. **Error Feedback**
- Display error messages prominently
- Provide actionable next steps
- Don't lose user's work during errors

### 4. **Platform Icons**
- Use official brand colors and icons
- Ensure icons are accessible (alt text)
- Consider dark mode support

### 5. **Empty State**
- Show helpful message when no accounts connected
- Prominently display "Connect" buttons
- Explain benefits of connecting accounts

---

## Security Considerations

### 1. **Never Store Tokens in Frontend**
- Access tokens and refresh tokens are server-side only
- Frontend only needs account metadata

### 2. **CSRF Protection**
- Mixpost handles CSRF for OAuth flows
- Ensure session cookies are sent with requests

### 3. **Authorization**
- All API calls require authentication
- Organization-scoped access control is enforced server-side

### 4. **Sensitive Data**
- Don't display full access tokens in UI
- Don't log tokens in console
- Handle errors without exposing token details

---

## Testing Checklist

- [ ] User can view list of connected accounts
- [ ] User can initiate OAuth flow for each platform
- [ ] OAuth callback succeeds and creates account
- [ ] User is redirected back to app after OAuth
- [ ] Success/error messages display correctly
- [ ] User can disconnect account
- [ ] Disconnecting removes account from list
- [ ] User can sync account data
- [ ] Multiple accounts of same platform are supported
- [ ] Error messages are user-friendly
- [ ] Loading states work correctly
- [ ] Session management works (cookies maintained)
- [ ] Multi-entity selection works (Facebook, LinkedIn)
- [ ] Mastodon server URL input works
- [ ] Empty state displays when no accounts
- [ ] Platform icons and branding are correct

---

## Platform-Specific Notes

### Twitter/X
- Uses OAuth 1.0a
- Requires write permissions for posting
- Character limit: 280

### Facebook/Instagram
- Uses Facebook Graph API
- Instagram requires business/creator account
- May require additional app review for permissions

### LinkedIn
- Can connect personal profile or company pages
- Limited posting features (text + single image)

### TikTok
- Requires TikTok for Business account
- Video-only platform

### YouTube
- Requires channel creation
- Video uploads only

### Pinterest
- Board-based organization
- Image/video pins

---

## Additional Resources

### Backend Files
- **API Controller**: `app/Http/Controllers/Api/V1/SocialAccountController.php`
  - `connect()` method returns JSON with OAuth URL (SPA-friendly)
- **Mixpost Provider Manager**: `packages/mixpost/src/Facades/SocialProviderManager.php`
  - Handles OAuth flow for all platforms
- **Callback Handler**: `packages/mixpost/src/Http/Controllers/CallbackSocialProviderController.php`
  - Receives OAuth callback, exchanges tokens, creates account
- **Model**: `app/Models/SocialAccount.php`
- **Routes**: 
  - API: `routes/api.php` (line 207: `/api/v1/social-accounts/connect/{platform}`)
  - Callback: `packages/mixpost/routes/web.php` (line 130: `/mixpost/callback/{provider}`)

### Frontend Examples
- Velocity Frontend: `velocity-frontend/src/lib/api.ts` (line 915)
- API Docs: `resources/frontend/src/docs/02-api-endpoints.md`
Ensure API endpoint exists**: The `connect()` method should be in `SocialAccountController`
2. **Add UI components**: Create social accounts page/components
3. **Implement API service**: Use the provided service layer code (see above)
4. **Make AJAX requests**: Always use `fetch/axios` with `Accept: application/json` header
5. **Test the flow**: 
   - Click "Connect Twitter"
   - AJAX request gets OAuth URL
   - Redirect to Twitter
   - Authorize
   - Return to your app
   - Account appears in list
6. **Expand to other platforms**: Repeat for Facebook, Instagram, etc.

## Common Mistakes to Avoid

### ❌ Mistake 0: Wrong URL Path
```typescript
// WRONG - This URL doesn't exist!
'/api/v1/mixpost/accounts/add/twitter'
```

### ✅ Solution: Use Correct API Path
```typescript
// CORRECT - Use this URL
'/api/v1/social-accounts/connect/twitter'
```

**Common variations that are WRONG:**
- ❌ `/api/v1/mixpost/accounts/add/{platform}`
- ❌ `/mixpost/accounts/add/{platform}` (this is the old Inertia route)
- ❌ `/api/social-accounts/connect/{platform}` (missing v1)

**Only this is CORRECT:**
- ✅ `/api/v1/social-accounts/connect/{platform}`

---

### ❌ Mistake 1: Direct Navigation
```typescript
// This makes a document request, not AJAX!
<a href="/api/v1/social-accounts/connect/twitter">Connect</a>
window.location.href = '/api/v1/social-accounts/connect/twitter';
```

### ✅ Solution: AJAX First
```typescript
async function connect() {
  const res = await fetch('/api/v1/social-accounts/connect/twitter', {
    headers: { 'Accept': 'application/json' }
  });
  const { auth_url } = await res.json();
  window.location.href = auth_url;
}
```

### ❌ Mistake 2: Wrong Accept Header
```typescript
// Browser thinks this is a page navigation
headers: { 'Accept': 'text/html' }
```

### ✅ Solution: JSON Accept Header
```typescript
headers: { 'Accept': 'application/json' }
```

### ❌ Mistake 3: Using Old Mixpost Route Directly
```typescript
// This is the old Inertia.js route, not for SPAs
window.location.href = '/mixpost/accounts/add/twitter';
```

### ✅ Solution: Use New API Route
```typescript
// Use the new API route that returns JSON
const res = await fetch('/api/v1/social-accounts/connect/twitter', {
  headers: { 'Accept': 'application/json' }
});
```

---

## Troubleshooting

### Error: "Bad Authentication data" (Twitter)

**Symptoms:**
```json
{
  "message": "{\"errors\":[{\"code\":215,\"message\":\"Bad Authentication data.\"}]}",
  "status": 500
}
```

**Causes:**
1. Missing Twitter API credentials in `.env`
2. Incorrect Twitter API credentials
3. Wrong callback URL configured in Twitter Developer Portal
4. App not approved for OAuth 1.0a

**Solutions:**

1. **Check `.env` file has Twitter credentials:**
```env
MIXPOST_TWITTER_CLIENT_ID=your_consumer_key
MIXPOST_TWITTER_CLIENT_SECRET=your_consumer_secret
```

2. **Verify credentials are correct** (from Twitter Developer Portal)

3. **Check callback URL in Twitter Developer Portal:**
   - Should be: `https://yourdomain.com/mixpost/callback/twitter`
   - Must match exactly (https, no trailing slash)

4. **Clear Laravel cache:**
```bash
php artisan config:clear
php artisan cache:clear
```

5. **Test credentials directly:**
```bash
# Check if credentials are loaded
php artisan tinker
>>> config('services.twitter')
```

---

### Error: 404 Not Found

**Wrong URL:**
```
GET /api/v1/mixpost/accounts/add/twitter → 404
```

**Correct URL:**
```
GET /api/v1/social-accounts/connect/twitter → 200
```

**Fix:** Update your frontend to use the correct endpoint path.

---

### Error: 401 Unauthorized

**Causes:**
- Missing `Authorization` header
- Expired JWT token
- Invalid token

**Solution:**
```typescript
const response = await fetch('/api/v1/social-accounts/connect/twitter', {
  headers: {
    'Accept': 'application/json',
    'Authorization': `Bearer ${yourToken}`, // Make sure token is valid
    'X-Organization-ID': `${orgId}`
  }
});
```

---

### Error: CORS Issues

**Symptoms:**
- Request blocked by CORS policy
- No 'Access-Control-Allow-Origin' header

**Solutions:**

1. **Ensure credentials are included:**
```typescript
const response = await fetch('/api/v1/social-accounts/connect/twitter', {
  credentials: 'include', // Important for session cookies
  headers: { ... }
});
```

2. **Check Laravel CORS config** (`config/cors.php`):
```php
'paths' => ['api/*', 'mixpost/*'],
'supports_credentials' => true,
```

---

### OAuth Callback Not Working

**Symptoms:**
- User authorizes on Twitter but gets error on return
- Infinite redirect loop
- Callback URL error on Twitter

**Solutions:**

1. **Verify callback URL is registered in platform:**
   - Twitter: `https://yourdomain.com/mixpost/callback/twitter`
   - Facebook: `https://yourdomain.com/mixpost/callback/facebook`
   - Must be EXACTLY as registered (https, no trailing slash)

2. **Check `.env` APP_URL is correct:**
```env
APP_URL=https://yourdomain.com
# NO trailing slash!
```

3. **Ensure Mixpost routes are loaded:**
```bash
php artisan route:list | grep mixpost
```

Should show:
```
GET|HEAD mixpost/callback/{provider} ... CallbackSocialProviderController
```

---

### Session Issues / State Mismatch

**Symptoms:**
- "State mismatch" error
- "Invalid state parameter" error

**Causes:**
- Session cookies not persisting
- Different domains (SPA vs API)
- Session expired during OAuth flow

**Solutions:**

1. **Ensure SESSION_DOMAIN is set correctly:**
```env
SESSION_DOMAIN=.yourdomain.com
# Note the leading dot for subdomains
```

2. **Check session driver** (`.env`):
```env
SESSION_DRIVER=redis  # or database, file
# Don't use 'cookie' for OAuth flows
```

3. **Verify session cookies work:**
```typescript
// In browser console after visiting your app
document.cookie
// Should see 'laravel_session=...'
```

4. **Clear old sessions:**
```bash
php artisan session:flush
```igured
2. **Add UI components**: Create social accounts page/components
3. **Implement API service**: Use the provided service layer code
4. **Handle OAuth redirects**: Add callback route handling
5. **Test with one platform**: Start with Twitter/X as it's simplest
6. **Expand to other platforms**: Add remaining platforms one by one

---

## Support

For questions or issues:
1. Check Mixpost documentation
2. Review provider implementations in `packages/mixpost/src/SocialProviders/`
3. Test OAuth flow in browser dev tools (network tab)
4. Ensure proper session cookie configuration
