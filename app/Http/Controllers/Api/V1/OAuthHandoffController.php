<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Handles OAuth handoff token exchange for clients that can't receive cookies (Chrome extension).
 */
class OAuthHandoffController extends Controller
{
    /**
     * Exchange a handoff token for OAuth result.
     * 
     * This endpoint is used by Chrome extensions that receive a handoff_token
     * in the OAuth redirect URL. The token can be exchanged exactly once
     * to retrieve the OAuth result.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $token = $request->input('token');
        $cacheKey = "oauth_handoff:{$token}";

        // Retrieve and delete in one atomic operation
        $result = Cache::pull($cacheKey);

        if (!$result) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Token is invalid, expired, or already used',
            ], 400);
        }

        return response()->json($result);
    }

    /**
     * Get entities for selection after OAuth callback (for Facebook Pages, etc.)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEntities(Request $request): JsonResponse
    {
        $request->validate([
            'entity_token' => 'required|string|size:64',
        ]);

        $token = $request->input('entity_token');
        $cacheKey = "oauth_entity_selection:{$token}";

        $data = Cache::get($cacheKey);

        \Illuminate\Support\Facades\Log::info('OAuthHandoff getEntities: Cache lookup', [
            'token' => substr($token, 0, 10) . '...',
            'cache_key' => $cacheKey,
            'has_data' => !empty($data),
            'provider' => $data['provider'] ?? 'unknown',
        ]);

        if (!$data) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Entity selection token is invalid or expired',
            ], 400);
        }

        try {
            // Get the provider and fetch available entities
            $provider = \Inovector\Mixpost\Facades\SocialProviderManager::connect($data['provider']);
            
            // Check if we already have an access token (from a previous getEntities call)
            $accessToken = $data['access_token'] ?? null;
            
            \Illuminate\Support\Facades\Log::info('OAuthHandoff: Before token exchange', [
                'provider' => $data['provider'],
                'has_access_token' => !empty($accessToken),
                'callback_response_keys' => array_keys($data['callback_response'] ?? []),
            ]);
            
            if (!$accessToken) {
                // First call - exchange the auth code for an access token
                $accessToken = $provider->requestAccessToken($data['callback_response']);
                
                \Illuminate\Support\Facades\Log::info('OAuthHandoff: Token exchange result', [
                    'provider' => $data['provider'],
                    'has_error' => isset($accessToken['error']),
                    'error' => $accessToken['error'] ?? null,
                    'has_access_token' => isset($accessToken['access_token']),
                ]);
                
                if (isset($accessToken['error'])) {
                    return response()->json([
                        'error' => 'token_error',
                        'error_description' => $accessToken['error'],
                    ], 400);
                }
                
                // Store the access token in cache for the selectEntity call
                $data['access_token'] = $accessToken;
                Cache::put($cacheKey, $data, now()->addMinutes(10));
            }

            // Use stateless method for API context (no session available)
            $provider->setAccessTokenStateless($accessToken);
        
        \Illuminate\Support\Facades\Log::info('OAuthHandoff: Fetching entities', [
            'provider' => $data['provider'],
            'has_access_token' => !empty($accessToken),
        ]);
        
        // Get available entities (pages, accounts, etc.)
        $entitiesResponse = $provider->getEntities(withAccessToken: true);
        
        \Illuminate\Support\Facades\Log::info('OAuthHandoff: Entities response', [
            'provider' => $data['provider'],
            'has_error' => $entitiesResponse->hasError(),
            'status' => $entitiesResponse->status ?? 'unknown',
            'context_count' => count($entitiesResponse->context() ?? []),
            'context' => $entitiesResponse->context(),
        ]);
        
        if ($entitiesResponse->hasError()) {
            return response()->json([
                'error' => 'entities_fetch_failed',
                'error_description' => 'Failed to fetch available pages/accounts',
                'debug' => [
                    'provider' => $data['provider'],
                    'status' => $entitiesResponse->status->value ?? 'unknown',
                    'message' => $entitiesResponse->errorMessage ?? null,
                    'context' => $entitiesResponse->context(),
                ],
            ], 400);
        }

        return response()->json([
            'platform' => $data['provider'],
            'entities' => $entitiesResponse->context(),
            'entity_token' => $token, // Client needs to send this back when selecting
        ]);
        
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('OAuthHandoff: Exception in getEntities', [
                'provider' => $data['provider'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'entities_fetch_failed',
                'error_description' => 'Failed to fetch available pages/accounts',
                'debug' => [
                    'exception' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Complete entity selection and save the account.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function selectEntity(Request $request): JsonResponse
    {
        $request->validate([
            'entity_token' => 'required|string|size:64',
            'entity_id' => 'required|string',
        ]);

        $token = $request->input('entity_token');
        $entityId = $request->input('entity_id');
        $cacheKey = "oauth_entity_selection:{$token}";

        // Pull the data (one-time use)
        $data = Cache::pull($cacheKey);

        if (!$data) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Entity selection token is invalid, expired, or already used',
            ], 400);
        }

        try {
            $provider = \Inovector\Mixpost\Facades\SocialProviderManager::connect($data['provider']);
            
            // Use the cached access token (obtained during getEntities)
            $accessToken = $data['access_token'] ?? null;
            
            if (!$accessToken) {
                return response()->json([
                    'error' => 'token_error',
                    'error_description' => 'Access token not found. Please restart the OAuth flow.',
                ], 400);
            }

            // Use stateless method for API context (no session available)
            $provider->setAccessTokenStateless($accessToken);
            
            // Fetch all entities with access tokens and find the selected one
            $entitiesResponse = $provider->getEntities(withAccessToken: true);
            
            if ($entitiesResponse->hasError()) {
                return response()->json([
                    'error' => 'entities_fetch_failed',
                    'error_description' => 'Failed to fetch entities for selection',
                ], 400);
            }
            
            $entities = $entitiesResponse->context();
            $entity = collect($entities)->firstWhere('id', $entityId);
            
            if (!$entity) {
                return response()->json([
                    'error' => 'entity_not_found',
                    'error_description' => 'The selected entity was not found',
                ], 404);
            }

            // Get the page access token (for Facebook Pages)
            $pageAccessToken = $entity['access_token']['access_token'] ?? $entity['access_token'] ?? null;

            // Save the account
            $socialAccount = \App\Models\SocialAccount::updateOrCreate(
                [
                    'organization_id' => $data['org_id'],
                    'platform' => $data['provider'],
                    'platform_user_id' => $entity['id'] ?? $entityId,
                ],
                [
                    'username' => $entity['username'] ?? $entity['name'] ?? null,
                    'display_name' => $entity['name'] ?? $entity['username'] ?? null,
                    'avatar_url' => $entity['image'] ?? $entity['avatar'] ?? null,
                    'access_token' => $pageAccessToken ?? $accessToken['access_token'] ?? null,
                    'refresh_token' => $accessToken['refresh_token'] ?? null,
                    'token_expires_at' => isset($accessToken['expires_in']) 
                        ? now()->addSeconds($accessToken['expires_in']) 
                        : null,
                    'scopes' => $accessToken['scope'] ?? null,
                    'connected_by' => $data['user_id'],
                    'is_active' => true,
                    'connected_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'platform' => $data['provider'],
                'account_id' => $socialAccount->id,
                'username' => $socialAccount->username ?? $socialAccount->display_name,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Entity selection error', [
                'provider' => $data['provider'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'internal_error',
                'error_description' => 'An error occurred while selecting the entity',
            ], 500);
        }
    }
}
