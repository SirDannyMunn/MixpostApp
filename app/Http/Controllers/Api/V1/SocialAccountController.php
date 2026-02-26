<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\OAuthStateService;
use Illuminate\Http\Request;
use Inovector\Mixpost\Facades\SocialProviderManager;

class SocialAccountController extends Controller
{
    public function __construct(
        protected OAuthStateService $stateService
    ) {}

    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('viewAny', [Account::class, $organization]);
        
        $accounts = Account::forOrganization($organization->id)
            ->orderBy('provider')
            ->get()
            ->map(function ($account) {
                // Return API-safe representation
                return [
                    'id' => $account->id,
                    'uuid' => $account->uuid,
                    'platform' => $account->provider,
                    'platform_user_id' => $account->provider_id,
                    'username' => $account->username,
                    'display_name' => $account->name,
                    'avatar_url' => $account->image(),
                    'is_authorized' => $account->authorized,
                    'connected_by' => $account->connected_by,
                    'connected_at' => $account->connected_at?->toISOString(),
                    'created_at' => $account->created_at?->toISOString(),
                    'updated_at' => $account->updated_at?->toISOString(),
                ];
            });
            
        return response()->json($accounts);
    }

    public function connect(Request $request, string $platform)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [Account::class, $organization]);
        
        $values = [];
        
        // Generate encrypted OAuth state if return_url is provided
        // State is the canonical transport for cross-domain OAuth context
        if ($request->has('return_url')) {
            $client = $request->input('client', 'web'); // web|figma|chrome_ext
            
            $state = $this->stateService->encode(
                returnUrl: $request->input('return_url'),
                organizationId: $organization->id,
                userId: $request->user()->id,
                client: $client
            );
            
            $values['oauth_state'] = $state;
        }
        
        // Get OAuth URL from Mixpost provider with state in values
        $provider = SocialProviderManager::connect($platform, $values);
        $authUrl = $provider->getAuthUrl();
        
        return response()->json([
            'auth_url' => $authUrl
        ]);
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [Account::class, $organization]);
        
        $data = $request->validate([
            'platform' => 'required|in:instagram,tiktok,youtube,twitter,linkedin,facebook,pinterest',
            'platform_user_id' => 'required|string',
            'username' => 'required|string',
            'display_name' => 'sometimes|nullable|string',
            'avatar_url' => 'sometimes|nullable|url|max:2000',
            'access_token' => 'required|string',
            'refresh_token' => 'sometimes|nullable|string',
            'token_expires_at' => 'sometimes|nullable|date',
            'scopes' => 'sometimes|array',
        ]);
        
        // Create account using Mixpost's Account model
        $account = Account::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'provider' => $data['platform'],
                'provider_id' => $data['platform_user_id'],
            ],
            [
                'name' => $data['display_name'] ?? $data['username'],
                'username' => $data['username'],
                'authorized' => true,
                'access_token' => [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_at' => $data['token_expires_at'] ?? null,
                    'scope' => $data['scopes'] ?? null,
                ],
                'connected_by' => $request->user()->id,
                'connected_at' => now(),
            ]
        );
        
        return response()->json([
            'id' => $account->id,
            'uuid' => $account->uuid,
            'platform' => $account->provider,
            'platform_user_id' => $account->provider_id,
            'username' => $account->username,
            'display_name' => $account->name,
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $account = Account::findOrFail($id);
        $this->authorize('delete', $account);
        $account->delete();
        return response()->json(null, 204);
    }
}
