<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaundryOS\PhantomBrowseCore\Exceptions\ApiException;
use LaundryOS\PhantomBrowseCore\Services\ProxyAssignmentService;
use LaundryOS\PhantomBrowseCore\Services\ProxyProviderSyncService;
use LaundryOS\PhantomBrowseCore\Services\ProxyService;

class BrowserUseProxyController extends Controller
{
    public function __construct(
        protected readonly ProxyService $proxyService,
        protected readonly ProxyAssignmentService $assignmentService,
        protected readonly ProxyProviderSyncService $providerSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->organizationId($request);
        $items = $this->proxyService->listForOrganization($orgId, [
            'provider' => $request->input('provider'),
            'active' => $request->input('active'),
        ]);

        return response()->json([
            'data' => $items->map(fn ($p) => $this->proxyResource($p, includeSecret: false))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $data = $request->validate([
            'provider' => 'nullable|string|max:40',
            'providerProxyId' => 'nullable|string|max:191',
            'proxyUrl' => 'nullable|string|max:2048',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1000',
            'scheme' => 'nullable|string|max:20',
            'countryCode' => 'nullable|string|max:5',
            'source' => 'nullable|string|max:40',
            'scope' => 'nullable|in:organization,user',
            'userId' => 'nullable|string|max:64',
            'priority' => 'nullable|integer',
            'isActive' => 'nullable|boolean',
        ]);

        $parsed = $this->mergeProxyUrlParts($data);
        $proxy = $this->proxyService->create([
            'organization_id' => $orgId,
            'provider' => $data['provider'] ?? 'manual',
            'provider_proxy_id' => $data['providerProxyId'] ?? null,
            'host' => $parsed['host'] ?? null,
            'port' => $parsed['port'] ?? null,
            'username' => $parsed['username'] ?? ($data['username'] ?? null),
            'password' => $parsed['password'] ?? ($data['password'] ?? null),
            'scheme' => $parsed['scheme'] ?? ($data['scheme'] ?? 'http'),
            'country_code' => $data['countryCode'] ?? null,
            'source' => $data['source'] ?? 'platform_pool',
            'is_active' => $data['isActive'] ?? true,
        ], $orgId, (string) ($request->user()?->id ?? ''));

        $assignment = $this->assignmentService->create([
            'proxy_id' => (string) $proxy->id,
            'scope' => $data['scope'] ?? ((isset($data['userId']) && $data['userId']) ? 'user' : 'organization'),
            'user_id' => $data['userId'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'is_active' => $data['isActive'] ?? true,
        ], $orgId);

        return response()->json([
            'data' => $this->proxyResource($proxy) + ['assignment' => $assignment->toArray()],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $orgId = $this->organizationId($request);
        $proxy = $this->proxyService->findScopedOrFail($id, $orgId);

        return response()->json(['data' => $this->proxyResource($proxy)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $proxy = $this->proxyService->findScopedOrFail($id, $orgId);

        $data = $request->validate([
            'provider' => 'sometimes|string|max:40',
            'providerProxyId' => 'sometimes|nullable|string|max:191',
            'proxyUrl' => 'sometimes|nullable|string|max:2048',
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'username' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string|max:1000',
            'scheme' => 'sometimes|string|max:20',
            'countryCode' => 'sometimes|nullable|string|max:5',
            'source' => 'sometimes|string|max:40',
            'isActive' => 'sometimes|boolean',
            'isDiscontinued' => 'sometimes|boolean',
        ]);
        $parsed = $this->mergeProxyUrlParts($data);

        $proxy = $this->proxyService->update($proxy, [
            'provider' => $data['provider'] ?? $proxy->provider,
            'provider_proxy_id' => $data['providerProxyId'] ?? $proxy->provider_proxy_id,
            'host' => $parsed['host'] ?? ($data['host'] ?? $proxy->host),
            'port' => $parsed['port'] ?? ($data['port'] ?? $proxy->port),
            'username' => $parsed['username'] ?? ($data['username'] ?? $proxy->username),
            'password' => array_key_exists('password', $data) || array_key_exists('proxyUrl', $data) ? ($parsed['password'] ?? ($data['password'] ?? null)) : null,
            'scheme' => $parsed['scheme'] ?? ($data['scheme'] ?? $proxy->scheme),
            'country_code' => $data['countryCode'] ?? $proxy->country_code,
            'source' => $data['source'] ?? $proxy->source,
            'is_active' => $data['isActive'] ?? $proxy->is_active,
            'is_discontinued' => $data['isDiscontinued'] ?? $proxy->is_discontinued,
        ]);

        return response()->json(['data' => $this->proxyResource($proxy)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $proxy = $this->proxyService->findScopedOrFail($id, $orgId);
        $this->proxyService->softDiscontinue($proxy);

        return response()->json(null, 204);
    }

    public function createAssignment(Request $request, string $id): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $proxy = $this->proxyService->findScopedOrFail($id, $orgId);
        $data = $request->validate([
            'scope' => 'required|in:organization,user',
            'userId' => 'nullable|string|max:64',
            'priority' => 'nullable|integer',
            'isActive' => 'nullable|boolean',
            'effectiveFrom' => 'nullable|date',
            'effectiveTo' => 'nullable|date',
            'deactivationReason' => 'nullable|string|max:255',
        ]);

        $assignment = $this->assignmentService->create([
            'proxy_id' => (string) $proxy->id,
            'scope' => $data['scope'],
            'user_id' => $data['userId'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'is_active' => $data['isActive'] ?? true,
            'effective_from' => $data['effectiveFrom'] ?? null,
            'effective_to' => $data['effectiveTo'] ?? null,
            'deactivation_reason' => $data['deactivationReason'] ?? null,
        ], $orgId);

        return response()->json(['data' => $assignment->toArray()], 201);
    }

    public function updateAssignment(Request $request, string $id): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $assignment = $this->assignmentService->findScopedOrFail($id, $orgId);
        $data = $request->validate([
            'scope' => 'sometimes|in:organization,user',
            'userId' => 'sometimes|nullable|string|max:64',
            'priority' => 'sometimes|integer',
            'isActive' => 'sometimes|boolean',
            'effectiveFrom' => 'sometimes|nullable|date',
            'effectiveTo' => 'sometimes|nullable|date',
            'deactivationReason' => 'sometimes|nullable|string|max:255',
        ]);

        $assignment = $this->assignmentService->update($assignment, [
            'scope' => $data['scope'] ?? $assignment->scope,
            'user_id' => $data['userId'] ?? $assignment->user_id,
            'priority' => $data['priority'] ?? $assignment->priority,
            'is_active' => $data['isActive'] ?? $assignment->is_active,
            'effective_from' => $data['effectiveFrom'] ?? $assignment->effective_from,
            'effective_to' => $data['effectiveTo'] ?? $assignment->effective_to,
            'deactivation_reason' => $data['deactivationReason'] ?? $assignment->deactivation_reason,
        ]);

        return response()->json(['data' => $assignment->toArray()]);
    }

    public function syncWebshare(Request $request): JsonResponse
    {
        $this->assertOrgAdmin($request);
        $orgId = $this->organizationId($request);
        $result = $this->providerSyncService->syncWebShare($orgId, null, null);

        return response()->json(['data' => $result->toArray()]);
    }

    protected function organizationId(Request $request): string
    {
        $orgId = (string) ($request->attributes->get('organization')?->id ?? '');
        if ($orgId === '') {
            throw new ApiException('Organization context required', 400, 'Organization context required');
        }

        return $orgId;
    }

    protected function assertOrgAdmin(Request $request): void
    {
        $orgId = $this->organizationId($request);
        $userId = (string) ($request->user()?->id ?? '');
        $member = OrganizationMember::query()->where('organization_id', $orgId)->where('user_id', $userId)->first();
        $role = strtolower((string) ($member?->role ?? ''));
        if (!in_array($role, ['owner', 'admin'], true)) {
            throw new ApiException('Forbidden', 403, 'Only organization owner/admin can mutate proxies');
        }
    }

    /** @param array<string,mixed> $data */
    protected function mergeProxyUrlParts(array $data): array
    {
        $proxyUrl = is_string($data['proxyUrl'] ?? null) ? trim((string) $data['proxyUrl']) : '';
        if ($proxyUrl === '') {
            return [];
        }

        $parts = parse_url($proxyUrl);
        if (!is_array($parts) || !isset($parts['host'], $parts['port'])) {
            return [];
        }

        $username = isset($parts['user']) ? rawurldecode((string) $parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;

        return [
            'scheme' => isset($parts['scheme']) ? (string) $parts['scheme'] : 'http',
            'host' => (string) $parts['host'],
            'port' => (int) $parts['port'],
            'username' => $username,
            'password' => $password,
        ];
    }

    protected function proxyResource($proxy, bool $includeSecret = true): array
    {
        $payload = [
            'id' => (string) $proxy->id,
            'organizationId' => $proxy->organization_id,
            'provider' => (string) $proxy->provider,
            'providerProxyId' => $proxy->provider_proxy_id,
            'host' => (string) $proxy->host,
            'port' => (int) $proxy->port,
            'username' => $proxy->username,
            'scheme' => (string) $proxy->scheme,
            'countryCode' => $proxy->country_code,
            'isActive' => (bool) $proxy->is_active,
            'isDiscontinued' => (bool) $proxy->is_discontinued,
            'source' => (string) $proxy->source,
            'createdByUserId' => $proxy->created_by_user_id,
            'lastHealthAt' => $proxy->last_health_at?->toISOString(),
            'lastHealthStatus' => $proxy->last_health_status,
            'lastHealthError' => $proxy->last_health_error,
            'createdAt' => $proxy->created_at?->toISOString(),
            'updatedAt' => $proxy->updated_at?->toISOString(),
        ];

        if ($includeSecret) {
            $payload['proxyUrl'] = $this->proxyService->buildProxyUrl($proxy);
        }

        return $payload;
    }
}
