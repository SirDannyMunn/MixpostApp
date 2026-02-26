# Frontend OAuth Integration Requirements

**Backend API**: `https://social-scheduler-dev.usewebmania.com`  
**Your SPA**: `https://trait-viral-96814077.figma.site/`

---

## Overview

Connect social media accounts (LinkedIn, Twitter, Facebook, etc.) via OAuth. The backend handles all OAuth complexity - the frontend just needs to:

1. Redirect user to OAuth
2. Handle the callback query parameters

---

## API Endpoints

### 1. Initiate OAuth Connection

```
GET /api/v1/social-accounts/connect/{platform}
```

**Query Parameters:**
| Parameter | Required | Description |
|-----------|----------|-------------|
| `return_url` | Yes | URL to redirect after OAuth (your page) |
| `client` | No | `web` (default), `figma`, or `chrome_ext` |

**Headers:**
```
Authorization: Bearer {token}
X-Organization-Id: {org_id}
```

**Response:**
```json
{
  "auth_url": "https://linkedin.com/oauth/v2/authorization?..."
}
```

**Supported Platforms:**
- `linkedin`
- `twitter`
- `facebook`
- `instagram`
- `tiktok`
- `youtube`
- `pinterest`
- `threads`
- `mastodon`

---

## Integration Flow

### Step 1: Call Connect API

```typescript
const connectSocialAccount = async (platform: string) => {
  const response = await fetch(
    `${API_BASE}/api/v1/social-accounts/connect/${platform}?` + 
    new URLSearchParams({
      return_url: window.location.href,  // or specific callback page
      client: 'figma'
    }),
    {
      headers: {
        'Authorization': `Bearer ${accessToken}`,
        'X-Organization-Id': organizationId
      }
    }
  );
  
  const { auth_url } = await response.json();
  
  // Redirect to OAuth provider
  window.location.href = auth_url;
};
```

### Step 2: Handle Callback

After OAuth completes, user is redirected back to your `return_url` with query parameters.

```typescript
// On page load, check for OAuth callback params
const handleOAuthCallback = () => {
  const params = new URLSearchParams(window.location.search);
  
  // Success
  if (params.get('success') === 'true') {
    const platform = params.get('platform');
    const accountId = params.get('account_id');
    const username = params.get('username');
    
    showToast(`Connected ${platform} account: ${username}`);
    refreshAccountsList();
    
    // Clean URL
    window.history.replaceState({}, '', window.location.pathname);
    return;
  }
  
  // Error
  if (params.get('error')) {
    const error = params.get('error');
    const description = params.get('error_description') || '';
    const platform = params.get('platform');
    
    showToast(`Failed to connect ${platform}: ${description}`, 'error');
    
    // Clean URL
    window.history.replaceState({}, '', window.location.pathname);
    return;
  }
  
  // Entity selection required (Facebook Pages, etc.)
  if (params.get('status') === 'entity_selection_required') {
    const platform = params.get('platform');
    const entityToken = params.get('entity_token');
    
    showEntitySelectionDialog(platform, entityToken);
    return;
  }
};

// Call on page load
useEffect(() => {
  handleOAuthCallback();
}, []);
```

---

## Callback Query Parameters

### Success
```
?success=true
&platform=linkedin
&account_id=123
&username=johndoe
```

### Error
```
?error=access_denied
&error_description=User%20denied%20access
&platform=linkedin
```

Common errors:
- `access_denied` - User cancelled OAuth
- `token_error` - Failed to get access token
- `account_fetch_failed` - Got token but couldn't fetch account info
- `internal_error` - Server error

### Entity Selection Required
```
?status=entity_selection_required
&platform=facebook
&entity_token=abc123...
```

This happens for Facebook Pages, Instagram Business accounts, etc. where the user needs to select which page/account to connect.

---

## Entity Selection Flow (Facebook Pages, Instagram Business)

When you receive `status=entity_selection_required`, the user has authenticated but needs to select which Page/account to connect.

**This is required for:** Facebook Pages, Instagram Business accounts

### URL You'll Receive:
```
https://your-spa.com/?status=entity_selection_required&platform=facebook&entity_token=ETQCZwYOsh0DTN4da3VMb2vhlCpIDto1...
```

### Step-by-Step Implementation

#### 1. Detect Entity Selection on Page Load

```typescript
const handleOAuthCallback = async () => {
  const params = new URLSearchParams(window.location.search);
  
  // Check for entity selection required FIRST
  if (params.get('status') === 'entity_selection_required') {
    const platform = params.get('platform');
    const entityToken = params.get('entity_token');
    
    // Clean URL immediately
    window.history.replaceState({}, '', window.location.pathname);
    
    // Show entity selection modal
    await showEntitySelectionModal(platform!, entityToken!);
    return;
  }
  
  // Then check for success
  if (params.get('success') === 'true') {
    toast.success(`Connected ${params.get('username')} on ${params.get('platform')}`);
    window.history.replaceState({}, '', window.location.pathname);
    refreshAccountsList();
    return;
  }
  
  // Then check for error
  if (params.get('error')) {
    toast.error(params.get('error_description') || params.get('error'));
    window.history.replaceState({}, '', window.location.pathname);
    return;
  }
};

useEffect(() => {
  handleOAuthCallback();
}, []);
```

#### 2. Fetch Available Entities (Pages)

```typescript
const fetchEntities = async (entityToken: string) => {
  const response = await fetch(
    `${API_BASE}/api/v1/oauth/entities?entity_token=${entityToken}`,
    {
      headers: {
        'Authorization': `Bearer ${accessToken}`,
        'X-Organization-Id': organizationId
      }
    }
  );
  
  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.error_description || 'Failed to fetch pages');
  }
  
  return response.json();
};
```

**Response Format:**
```json
{
  "platform": "facebook",
  "entities": [
    {
      "id": "123456789",
      "name": "My Business Page",
      "image": "https://graph.facebook.com/123456789/picture"
    },
    {
      "id": "987654321",
      "name": "My Other Page", 
      "image": "https://graph.facebook.com/987654321/picture"
    }
  ],
  "entity_token": "ETQCZwYOsh0DTN4da3VMb2vhlCpIDto1..."
}
```

#### 3. Show Selection UI and Submit

```typescript
const selectEntity = async (entityToken: string, entityId: string) => {
  const response = await fetch(`${API_BASE}/api/v1/oauth/entities/select`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'X-Organization-Id': organizationId,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      entity_token: entityToken,
      entity_id: entityId
    })
  });
  
  return response.json();
};
```

**Success Response:**
```json
{
  "success": true,
  "account_id": "019bc26a-xxxx-xxxx",
  "platform": "facebook",
  "username": "My Business Page"
}
```

**Error Response:**
```json
{
  "error": "token_expired",
  "error_description": "Entity selection token has expired. Please try connecting again."
}
```

### Complete React Component Example

```tsx
import { useEffect, useState } from 'react';

interface Entity {
  id: string;
  name: string;
  image?: string;
}

// Modal component for selecting a Facebook Page
function EntitySelectionModal({ 
  platform, 
  entityToken, 
  onClose, 
  onSuccess 
}: {
  platform: string;
  entityToken: string;
  onClose: () => void;
  onSuccess: () => void;
}) {
  const [entities, setEntities] = useState<Entity[]>([]);
  const [loading, setLoading] = useState(true);
  const [selecting, setSelecting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadEntities();
  }, []);

  const loadEntities = async () => {
    try {
      const response = await fetch(
        `${API_BASE}/api/v1/oauth/entities?entity_token=${entityToken}`,
        {
          headers: {
            'Authorization': `Bearer ${accessToken}`,
            'X-Organization-Id': organizationId
          }
        }
      );
      
      const data = await response.json();
      
      if (!response.ok) {
        throw new Error(data.error_description || 'Failed to load pages');
      }
      
      setEntities(data.entities || []);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleSelect = async (entityId: string) => {
    setSelecting(entityId);
    setError(null);
    
    try {
      const response = await fetch(`${API_BASE}/api/v1/oauth/entities/select`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'X-Organization-Id': organizationId,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          entity_token: entityToken,
          entity_id: entityId
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        toast.success(`Connected ${data.username}!`);
        onSuccess();
        onClose();
      } else {
        setError(data.error_description || 'Failed to connect');
      }
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSelecting(null);
    }
  };

  return (
    <div className="modal-overlay">
      <div className="modal">
        <h2>Select a {platform === 'facebook' ? 'Facebook Page' : 'Account'}</h2>
        
        {loading && <p>Loading pages...</p>}
        
        {error && (
          <div className="error-message">
            {error}
            <button onClick={onClose}>Close</button>
          </div>
        )}
        
        {!loading && !error && entities.length === 0 && (
          <div>
            <p>No pages found.</p>
            <p>Make sure you have admin access to at least one Facebook Page.</p>
            <button onClick={onClose}>Close</button>
          </div>
        )}
        
        {!loading && entities.length > 0 && (
          <ul className="entity-list">
            {entities.map(entity => (
              <li key={entity.id}>
                <button
                  onClick={() => handleSelect(entity.id)}
                  disabled={selecting !== null}
                  className="entity-button"
                >
                  {entity.image && (
                    <img src={entity.image} alt="" className="entity-avatar" />
                  )}
                  <span className="entity-name">{entity.name}</span>
                  {selecting === entity.id && (
                    <span className="loading-spinner">Connecting...</span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        )}
        
        <button 
          onClick={onClose} 
          disabled={selecting !== null}
          className="cancel-button"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

// Main component - shows modal when needed
export function SocialAccountsPage() {
  const [entityModal, setEntityModal] = useState<{
    platform: string;
    token: string;
  } | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    
    if (params.get('status') === 'entity_selection_required') {
      setEntityModal({
        platform: params.get('platform')!,
        token: params.get('entity_token')!
      });
      window.history.replaceState({}, '', window.location.pathname);
    }
    // ... handle other cases
  }, []);

  return (
    <div>
      {/* Your normal UI */}
      
      {entityModal && (
        <EntitySelectionModal
          platform={entityModal.platform}
          entityToken={entityModal.token}
          onClose={() => setEntityModal(null)}
          onSuccess={() => refreshAccountsList()}
        />
      )}
    </div>
  );
}
```

### API Reference

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/oauth/entities?entity_token=...` | GET | Fetch available Pages/accounts |
| `/api/v1/oauth/entities/select` | POST | Select and connect a Page |

### Important Notes

| Note | Details |
|------|---------|
| **Token expiry** | Entity token expires in **10 minutes**. If expired, user must restart OAuth. |
| **No pages found?** | User doesn't have admin access to any Facebook Page. They need to create one first. |
| **Instagram** | Same flow - entities will be Instagram Business accounts linked to Facebook Pages. |
| **Multiple pages** | To connect multiple pages, user repeats the OAuth flow for each. |

---

## Complete Example Component

```tsx
import { useEffect, useState } from 'react';

const PLATFORMS = [
  { id: 'linkedin', name: 'LinkedIn', icon: '💼' },
  { id: 'twitter', name: 'Twitter/X', icon: '𝕏' },
  { id: 'facebook', name: 'Facebook', icon: '📘' },
  { id: 'instagram', name: 'Instagram', icon: '📸' },
  { id: 'tiktok', name: 'TikTok', icon: '🎵' },
  { id: 'youtube', name: 'YouTube', icon: '▶️' },
];

export function ConnectAccounts() {
  const [loading, setLoading] = useState<string | null>(null);
  
  // Handle OAuth callback on mount
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    
    if (params.get('success') === 'true') {
      toast.success(`Connected ${params.get('username')} on ${params.get('platform')}`);
      window.history.replaceState({}, '', window.location.pathname);
    } else if (params.get('error')) {
      toast.error(params.get('error_description') || params.get('error'));
      window.history.replaceState({}, '', window.location.pathname);
    }
  }, []);
  
  const connect = async (platform: string) => {
    setLoading(platform);
    
    try {
      const res = await api.get(`/social-accounts/connect/${platform}`, {
        params: {
          return_url: window.location.href,
          client: 'figma'
        }
      });
      
      window.location.href = res.data.auth_url;
    } catch (err) {
      toast.error('Failed to start OAuth');
      setLoading(null);
    }
  };
  
  return (
    <div>
      <h2>Connect Social Accounts</h2>
      {PLATFORMS.map(p => (
        <button 
          key={p.id}
          onClick={() => connect(p.id)}
          disabled={loading !== null}
        >
          {p.icon} {loading === p.id ? 'Connecting...' : `Connect ${p.name}`}
        </button>
      ))}
    </div>
  );
}
```

---

## Notes

- The `return_url` must be on an allowed domain (your Figma site is already allowed)
- OAuth token is stored in the backend - you just see `account_id`
- Entity selection token expires in 10 minutes
- After connecting, call `GET /api/v1/social-accounts` to refresh your account list

---

## Testing

1. Click connect button
2. You'll be redirected to the social platform's OAuth page
3. Authorize the app
4. You'll be redirected back to your `return_url` with success/error params
5. Your callback handler shows toast and refreshes accounts
