# Frontend Requirements: Social Account Connection with Mixpost

This document outlines the frontend requirements for integrating social account OAuth connections using the Mixpost package from your web application.

---

## Overview

The Mixpost package provides a complete OAuth flow for connecting social media accounts. Your frontend needs to:

1. **Initiate** the OAuth flow by redirecting users to the platform's authorization page
2. **Handle** the callback after authorization completes
3. **Display** connected accounts and manage their state
4. **Sync** account data periodically

---

## Supported Platforms

| Platform | Provider Key | Notes |
|----------|-------------|-------|
| X (Twitter) | `twitter` | OAuth 1.0a |
| Facebook Pages | `facebook_page` | OAuth 2.0, requires page selection |
| Instagram | `instagram` | Via Meta Business API |
| LinkedIn | `linkedin` | OAuth 2.0 |
| TikTok | `tiktok` | OAuth 2.0 |
| YouTube | `youtube` | OAuth 2.0, Google API |
| Pinterest | `pinterest` | OAuth 2.0 |
| Mastodon | `mastodon` | Requires server selection first |

---

## API Endpoints

### 1. List Connected Accounts

```http
GET /api/v1/social-accounts
```

**Query Parameters:**
- `platform` (optional): Filter by platform name
- `is_active` (optional): Filter by active status

**Response:**
```json
[
  {
    "id": "uuid-here",
    "platform": "twitter",
    "platform_user_id": "123456789",
    "username": "acmecorp",
    "display_name": "ACME Corp",
    "avatar_url": "https://...",
    "is_active": true,
    "last_sync_at": "2025-01-15T10:00:00Z",
    "connected_at": "2025-01-01T00:00:00Z",
    "scopes": ["tweet.read", "tweet.write", "users.read"]
  }
]
```

---

### 2. Initiate OAuth Connection

The OAuth flow uses the **Mixpost internal routes**, not API routes. This requires a different approach depending on your frontend architecture.

#### Option A: Inertia/Vue SPA (Mixpost Default)

If using Inertia.js with the built-in Mixpost frontend:

```http
POST /mixpost/accounts/add/{provider}
```

This route automatically redirects to the OAuth provider.

#### Option B: Headless/External SPA (React, Vue, etc.)

For headless integrations, you need to create an API endpoint that returns the OAuth URL:

**New Endpoint to Create:**
```http
GET /api/v1/social-accounts/connect/{platform}
```

**Response:**
```json
{
  "auth_url": "https://api.twitter.com/oauth/authorize?oauth_token=...",
  "state": "csrf-token-here"
}
```

---

### 3. OAuth Callback

```http
GET /mixpost/callback/{provider}
```

**Query Parameters (from OAuth provider):**
- `code`: Authorization code (OAuth 2.0)
- `oauth_token`, `oauth_verifier`: OAuth 1.0a tokens (Twitter)
- `state`: CSRF token
- `error`: Error message if authorization failed

**Behavior:**
- On success: Redirects to `/mixpost/accounts` (or configured success URL)
- On error: Redirects with error message in session flash

---

### 4. Disconnect Account

```http
DELETE /api/v1/social-accounts/{id}
```

**Response:** `204 No Content`

---

### 5. Sync Account Data

```http
POST /api/v1/social-accounts/{id}/sync
```

**Response:**
```json
{
  "status": "synced",
  "last_sync_at": "2025-01-15T16:00:00Z"
}
```

---

## Frontend Implementation Guide

### 1. TypeScript Types

```typescript
// types/social-accounts.ts

export type SocialPlatform = 
  | 'twitter'
  | 'facebook_page'
  | 'instagram'
  | 'linkedin'
  | 'tiktok'
  | 'youtube'
  | 'pinterest'
  | 'mastodon';

export interface SocialAccount {
  id: string;
  organization_id: string;
  platform: SocialPlatform;
  platform_user_id: string;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  is_active: boolean;
  last_sync_at: string | null;
  connected_at: string;
  scopes: string[] | null;
}

export interface OAuthStartResponse {
  auth_url: string;
  state?: string;
}

export interface OAuthCallbackParams {
  code?: string;
  oauth_token?: string;
  oauth_verifier?: string;
  state?: string;
  error?: string;
  error_description?: string;
}
```

---

### 2. API Service Layer

```typescript
// services/social-accounts.ts

import { httpClient } from './http-client';
import type { SocialAccount, SocialPlatform, OAuthStartResponse } from '../types/social-accounts';

export const socialAccountsApi = {
  /**
   * Get all connected social accounts
   */
  async list(params?: { platform?: string; is_active?: boolean }): Promise<SocialAccount[]> {
    const response = await httpClient.get<SocialAccount[]>('/social-accounts', { params });
    return response;
  },

  /**
   * Initiate OAuth connection flow
   * Opens the OAuth provider's authorization page
   */
  async connect(platform: SocialPlatform): Promise<OAuthStartResponse> {
    return await httpClient.get<OAuthStartResponse>(`/social-accounts/connect/${platform}`);
  },

  /**
   * Disconnect a social account
   */
  async disconnect(accountId: string): Promise<void> {
    await httpClient.delete(`/social-accounts/${accountId}`);
  },

  /**
   * Sync account data from the platform
   */
  async sync(accountId: string): Promise<{ status: string; last_sync_at: string }> {
    return await httpClient.post(`/social-accounts/${accountId}/sync`);
  },
};
```

---

### 3. OAuth Flow Implementation

#### Step 1: Initiate Connection

```typescript
// hooks/useSocialAccountConnect.ts

import { useState } from 'react';
import { socialAccountsApi } from '../services/social-accounts';
import type { SocialPlatform } from '../types/social-accounts';

export function useSocialAccountConnect() {
  const [isConnecting, setIsConnecting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const connect = async (platform: SocialPlatform) => {
    setIsConnecting(true);
    setError(null);

    try {
      // For Mastodon, you may need to prompt for server first
      if (platform === 'mastodon') {
        // Show server selection modal first
        return;
      }

      const { auth_url } = await socialAccountsApi.connect(platform);
      
      // Redirect to OAuth provider
      // The callback will redirect back to your app
      window.location.href = auth_url;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to start connection');
      setIsConnecting(false);
    }
  };

  return { connect, isConnecting, error };
}
```

#### Step 2: Handle OAuth Callback

Create a callback page that processes the OAuth response:

```typescript
// pages/SocialAccountCallback.tsx

import { useEffect, useState } from 'react';
import { useSearchParams, useParams, useNavigate } from 'react-router-dom';

export function SocialAccountCallback() {
  const [status, setStatus] = useState<'processing' | 'success' | 'error'>('processing');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [searchParams] = useSearchParams();
  const { platform } = useParams();
  const navigate = useNavigate();

  useEffect(() => {
    const handleCallback = async () => {
      // Check for OAuth errors
      const error = searchParams.get('error');
      if (error) {
        setStatus('error');
        setErrorMessage(searchParams.get('error_description') || error);
        return;
      }

      // The actual token exchange happens on the backend
      // If we reach this page without error, the backend has handled it
      // Just redirect to accounts page

      setStatus('success');
      
      // Redirect after brief delay to show success state
      setTimeout(() => {
        navigate('/settings/social-accounts', { 
          state: { message: `Successfully connected ${platform} account!` }
        });
      }, 1500);
    };

    handleCallback();
  }, [searchParams, platform, navigate]);

  return (
    <div className="flex items-center justify-center min-h-screen">
      {status === 'processing' && (
        <div className="text-center">
          <div className="animate-spin w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4" />
          <p>Connecting your {platform} account...</p>
        </div>
      )}
      
      {status === 'success' && (
        <div className="text-center text-green-500">
          <div className="text-4xl mb-4">✓</div>
          <p>Successfully connected!</p>
        </div>
      )}
      
      {status === 'error' && (
        <div className="text-center text-red-500">
          <div className="text-4xl mb-4">✗</div>
          <p>Failed to connect: {errorMessage}</p>
          <button 
            onClick={() => navigate('/settings/social-accounts')}
            className="mt-4 px-4 py-2 bg-gray-700 rounded"
          >
            Go Back
          </button>
        </div>
      )}
    </div>
  );
}
```

---

### 4. Connected Accounts UI Component

```typescript
// components/ConnectedAccounts.tsx

import { useState, useEffect } from 'react';
import { socialAccountsApi } from '../services/social-accounts';
import type { SocialAccount, SocialPlatform } from '../types/social-accounts';

interface PlatformConfig {
  name: string;
  icon: string;
  gradient: string;
}

const PLATFORMS: Record<SocialPlatform, PlatformConfig> = {
  twitter: { name: 'X', icon: '𝕏', gradient: 'from-black to-gray-800' },
  facebook_page: { name: 'Facebook Page', icon: 'f', gradient: 'from-blue-600 to-blue-700' },
  instagram: { name: 'Instagram', icon: '📸', gradient: 'from-purple-500 via-pink-500 to-orange-500' },
  linkedin: { name: 'LinkedIn', icon: 'in', gradient: 'from-blue-500 to-blue-600' },
  tiktok: { name: 'TikTok', icon: '♪', gradient: 'from-black to-pink-600' },
  youtube: { name: 'YouTube', icon: '▶', gradient: 'from-red-600 to-red-700' },
  pinterest: { name: 'Pinterest', icon: 'P', gradient: 'from-red-500 to-red-600' },
  mastodon: { name: 'Mastodon', icon: '🐘', gradient: 'from-purple-600 to-purple-800' },
};

export function ConnectedAccounts() {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddModal, setShowAddModal] = useState(false);

  useEffect(() => {
    loadAccounts();
  }, []);

  const loadAccounts = async () => {
    try {
      const data = await socialAccountsApi.list();
      setAccounts(data);
    } finally {
      setLoading(false);
    }
  };

  const handleConnect = async (platform: SocialPlatform) => {
    try {
      const { auth_url } = await socialAccountsApi.connect(platform);
      window.location.href = auth_url;
    } catch (error) {
      console.error('Failed to initiate connection:', error);
    }
  };

  const handleDisconnect = async (accountId: string) => {
    if (!confirm('Are you sure you want to disconnect this account?')) return;
    
    try {
      await socialAccountsApi.disconnect(accountId);
      setAccounts(prev => prev.filter(a => a.id !== accountId));
    } catch (error) {
      console.error('Failed to disconnect:', error);
    }
  };

  const handleSync = async (accountId: string) => {
    try {
      const result = await socialAccountsApi.sync(accountId);
      setAccounts(prev => prev.map(a => 
        a.id === accountId ? { ...a, last_sync_at: result.last_sync_at } : a
      ));
    } catch (error) {
      console.error('Failed to sync:', error);
    }
  };

  if (loading) return <div>Loading accounts...</div>;

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Connected Accounts</h2>
        <button 
          onClick={() => setShowAddModal(true)}
          className="px-4 py-2 bg-green-600 rounded-lg hover:bg-green-700"
        >
          + Add Account
        </button>
      </div>

      {/* Account Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {accounts.map(account => {
          const platform = PLATFORMS[account.platform];
          return (
            <div key={account.id} className="bg-gray-800 rounded-xl p-4">
              <div className="flex items-center gap-3 mb-4">
                <div className={`w-12 h-12 rounded-full bg-gradient-to-br ${platform.gradient} flex items-center justify-center text-white font-bold`}>
                  {account.avatar_url ? (
                    <img src={account.avatar_url} className="w-full h-full rounded-full" />
                  ) : (
                    platform.icon
                  )}
                </div>
                <div>
                  <div className="font-medium">{account.display_name || account.username}</div>
                  <div className="text-sm text-gray-400">@{account.username}</div>
                </div>
              </div>
              
              <div className="flex items-center gap-2 mb-3">
                <span className={`px-2 py-0.5 rounded text-xs ${account.is_active ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}`}>
                  {account.is_active ? 'Active' : 'Inactive'}
                </span>
                <span className="text-xs text-gray-500">
                  {platform.name}
                </span>
              </div>

              <div className="text-xs text-gray-500 mb-4">
                Connected: {new Date(account.connected_at).toLocaleDateString()}
                {account.last_sync_at && (
                  <span> · Synced: {new Date(account.last_sync_at).toLocaleDateString()}</span>
                )}
              </div>

              <div className="flex gap-2">
                <button 
                  onClick={() => handleSync(account.id)}
                  className="flex-1 px-3 py-2 bg-blue-600/20 text-blue-400 rounded-lg hover:bg-blue-600/30 text-sm"
                >
                  Sync
                </button>
                <button 
                  onClick={() => handleDisconnect(account.id)}
                  className="flex-1 px-3 py-2 bg-red-600/20 text-red-400 rounded-lg hover:bg-red-600/30 text-sm"
                >
                  Disconnect
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* Add Account Modal */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
          <div className="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
            <h3 className="text-xl font-bold mb-4">Connect Social Account</h3>
            <p className="text-gray-400 mb-6">Select a platform to connect</p>
            
            <div className="grid grid-cols-2 gap-3">
              {(Object.entries(PLATFORMS) as [SocialPlatform, PlatformConfig][]).map(([key, config]) => (
                <button
                  key={key}
                  onClick={() => handleConnect(key)}
                  className="flex items-center gap-3 p-3 bg-gray-700 rounded-lg hover:ring-2 hover:ring-green-500"
                >
                  <div className={`w-10 h-10 rounded-full bg-gradient-to-br ${config.gradient} flex items-center justify-center text-white font-bold`}>
                    {config.icon}
                  </div>
                  <span>{config.name}</span>
                </button>
              ))}
            </div>
            
            <button 
              onClick={() => setShowAddModal(false)}
              className="w-full mt-4 px-4 py-2 bg-gray-700 rounded-lg"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
```

---

### 5. Router Configuration

Add these routes to handle the OAuth callback:

```typescript
// router.tsx (React Router example)

import { createBrowserRouter } from 'react-router-dom';
import { SocialAccountCallback } from './pages/SocialAccountCallback';
import { SettingsLayout } from './layouts/SettingsLayout';
import { ConnectedAccounts } from './components/ConnectedAccounts';

export const router = createBrowserRouter([
  // ... other routes
  
  // OAuth callback route
  {
    path: '/social-accounts/callback/:platform',
    element: <SocialAccountCallback />,
  },
  
  // Settings routes
  {
    path: '/settings',
    element: <SettingsLayout />,
    children: [
      {
        path: 'social-accounts',
        element: <ConnectedAccounts />,
      },
    ],
  },
]);
```

---

## Backend API Endpoint (Required)

You need to create this API endpoint in your Laravel app to support headless OAuth initiation:

```php
// app/Http/Controllers/Api/V1/SocialAccountOAuthController.php

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inovector\Mixpost\Facades\SocialProviderManager;

class SocialAccountOAuthController extends Controller
{
    /**
     * Initiate OAuth flow and return the auth URL
     */
    public function connect(string $platform): JsonResponse
    {
        $provider = SocialProviderManager::connect($platform);
        
        return response()->json([
            'auth_url' => $provider->getAuthUrl(),
        ]);
    }
}
```

Add the route:

```php
// routes/api.php

Route::get('/social-accounts/connect/{platform}', [
    \App\Http\Controllers\Api\V1\SocialAccountOAuthController::class, 
    'connect'
]);
```

---

## OAuth Callback Configuration

### Configure Callback URLs

In your `.env`, ensure the `APP_URL` is set correctly for OAuth callbacks:

```env
APP_URL=https://your-app.com
```

### Platform Developer Console Setup

For each platform, configure the OAuth callback URL:

| Platform | Callback URL Pattern |
|----------|---------------------|
| Twitter | `https://your-app.com/mixpost/callback/twitter` |
| Facebook | `https://your-app.com/mixpost/callback/facebook_page` |
| Instagram | Same as Facebook (uses Meta API) |
| LinkedIn | `https://your-app.com/mixpost/callback/linkedin` |
| TikTok | `https://your-app.com/mixpost/callback/tiktok` |
| YouTube | `https://your-app.com/mixpost/callback/youtube` |
| Pinterest | `https://your-app.com/mixpost/callback/pinterest` |

---

## Special Cases

### Facebook Pages & Entity Selection

Facebook and some other platforms (YouTube channels) require an additional step to select which page/channel to connect. The Mixpost package handles this via the `AccountEntitiesController`.

**Flow:**
1. User initiates OAuth → Redirects to Facebook
2. User authorizes → Redirects to callback
3. Callback detects multiple pages → Redirects to entity selection page
4. User selects page → Account is stored

**Frontend Handling:**

```typescript
// You may need to handle the entity selection redirect
// The Mixpost callback may redirect to /mixpost/accounts/entities/{provider}

// Create a page to display and select entities
export function EntitySelectionPage() {
  // This page shows available Facebook pages, YouTube channels, etc.
  // User selects which one to connect
}
```

### Mastodon Server Selection

Mastodon requires the user to specify their server first:

```typescript
const handleMastodonConnect = async () => {
  // Show modal to get server name
  const server = await showServerModal();
  
  // Then initiate OAuth with server parameter
  const { auth_url } = await httpClient.get(`/social-accounts/connect/mastodon`, {
    params: { server }
  });
  
  window.location.href = auth_url;
};
```

---

## Error Handling

### Common OAuth Errors

| Error | Description | User Action |
|-------|-------------|-------------|
| `access_denied` | User denied authorization | Show "You denied access" message |
| `invalid_scope` | Requested scopes not available | Contact support |
| `server_error` | Platform API error | Retry later |
| `token_expired` | Access token expired | Reconnect account |

### Token Refresh

Mixpost handles token refresh automatically for OAuth 2.0 providers. However, you should handle cases where reconnection is required:

```typescript
// If API returns 401 for a social account operation
// Show "Your X account needs to be reconnected" message
```

---

## Security Considerations

1. **CSRF Protection**: The `state` parameter in OAuth prevents CSRF attacks. Never skip this check.

2. **Token Storage**: Tokens are stored encrypted in the database. Never expose them to the frontend.

3. **Scopes**: Request only necessary scopes. Review each platform's required permissions.

4. **Callback URL Validation**: Only allow callbacks from registered OAuth providers.

---

## Testing

### Development Setup

For local development, use a tunnel service like ngrok:

```bash
ngrok http 8000
```

Update your `.env`:
```env
APP_URL=https://your-ngrok-url.ngrok.io
```

Update callback URLs in each platform's developer console.

### Test Accounts

Most platforms offer sandbox/test accounts for development:
- **Twitter**: Apply for developer access
- **Meta (Facebook/Instagram)**: Use test users in app dashboard
- **LinkedIn**: Use test companies
- **TikTok**: Sandbox mode available

---

## Checklist

- [ ] TypeScript types for social accounts
- [ ] API service layer with all endpoints
- [ ] OAuth initiation handler (redirect to auth URL)
- [ ] Callback page to process OAuth response
- [ ] Connected accounts list component
- [ ] Add account modal with platform selection
- [ ] Disconnect confirmation dialog
- [ ] Sync button functionality
- [ ] Error handling for OAuth failures
- [ ] Token expiration/reconnection handling
- [ ] Entity selection page (for Facebook pages, etc.)
- [ ] Mastodon server selection (if supporting Mastodon)
- [ ] Loading states and skeletons
- [ ] Success/error toast notifications
- [ ] Router configuration for callback routes
