<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaundryOS\PhantomBrowseCore\Exceptions\ApiException;
use LaundryOS\PhantomBrowseCore\Services\ApiKeyService;
use LaundryOS\PhantomBrowseCore\Services\BrowserSessionService;
use LaundryOS\PhantomBrowseCore\Services\ProfileService;
use LaundryOS\PhantomBrowseCore\Services\SecretService;
use LaundryOS\PhantomBrowseCore\Services\SessionService;
use LaundryOS\PhantomBrowseCore\Services\TaskService;
use LaundryOS\PhantomBrowseCore\Transformers\ResponseTransformer;

/**
 * Proxy controller for Browser Use-compatible API.
 *
 * Forwards requests from the frontend to PhantomBrowse Core services
 * (in-process), returning BrowserUse-compatible response shapes.
 */
class BrowserUseController extends Controller
{
    public function __construct(
        protected ApiKeyService $apiKeyService,
        protected SessionService $sessionService,
        protected TaskService $taskService,
        protected SecretService $secretService,
        protected BrowserSessionService $browserSessionService,
        protected ProfileService $profileService,
        protected ResponseTransformer $transformer,
    ) {}

    /**
     * Wrap a callback and forward BrowserUse API errors as proper HTTP responses.
     */
    private function proxy(callable $fn): JsonResponse|Response
    {
        try {
            return $fn();
        } catch (ApiException $e) {
            $status = $e->status() ?: 500;
            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Sessions ───

    public function listSessions(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->sessionService->listSessions([
                'pageSize' => (int) $request->input('pageSize', 10),
                'pageNumber' => (int) $request->input('pageNumber', 1),
            ], $apiKey);

            return response()->json($this->transformer->paginated($result));
        });
    }

    public function createSession(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->sessionService->createSession([
                'profileId' => $request->input('profileId'),
                'proxyId' => $request->input('proxyId'),
                'proxyUrl' => $request->input('proxyUrl'),
                'proxyCountryCode' => $request->input('proxyCountryCode'),
                'useDedicatedUserProxy' => (bool) $request->boolean('useDedicatedUserProxy'),
                'disableProxy' => (bool) $request->boolean('disableProxy'),
                'organizationId' => (string) ($request->attributes->get('organization')?->id ?? ''),
                'userId' => (string) ($request->user()?->id ?? ''),
                'startUrl' => $request->input('startUrl'),
                'browserScreenWidth' => $request->input('browserScreenWidth') ? (int) $request->input('browserScreenWidth') : null,
                'browserScreenHeight' => $request->input('browserScreenHeight') ? (int) $request->input('browserScreenHeight') : null,
            ], $apiKey);

            return response()->json(['data' => $this->transformer->session($dto)]);
        });
    }

    public function getSession(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->sessionService->getSession($id, $apiKey);

            return response()->json(['data' => $this->transformer->session($dto)]);
        });
    }

    public function updateSession(Request $request, string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($request, $id) {
            $action = $request->input('action', 'stop');

            if ($action !== 'stop') {
                throw new ApiException('Invalid action', 422, [[
                    'loc' => ['body', 'action'],
                    'msg' => 'invalid stop action',
                    'type' => 'value_error',
                ]]);
            }

            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->sessionService->stopSession($id, $apiKey);

            return response()->json(['data' => $this->transformer->session($dto)]);
        });
    }

    public function deleteSession(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $this->sessionService->deleteSession($id, $apiKey);

            return response()->json(null, 204);
        });
    }

    // ─── Session Sharing ───

    public function getShare(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->sessionService->getPublicShare($id, $apiKey);

            return response()->json(['data' => $result]);
        });
    }

    public function createShare(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->sessionService->createOrUpdatePublicShare($id, $apiKey);

            return response()->json(['data' => $result]);
        });
    }

    public function deleteShare(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $this->sessionService->deletePublicShare($id, $apiKey);

            return response()->json(null, 204);
        });
    }

    // ─── Browsers ───

    public function listProfiles(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->profileService->listProfiles([
                'pageSize' => (int) $request->input('pageSize', 50),
                'pageNumber' => (int) $request->input('pageNumber', 1),
            ], $apiKey);

            return response()->json($this->transformer->paginated($result));
        });
    }

    public function createProfile(Request $request): JsonResponse|Response
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'defaultProxyId' => 'nullable|uuid',
            'groupsIds' => 'nullable|array',
            'cookieDomains' => 'nullable|array',
        ]);

        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->profileService->createProfile([
                'name' => $request->input('name'),
                'defaultProxyId' => $request->input('defaultProxyId'),
                'groupsIds' => $request->input('groupsIds', []),
                'cookieDomains' => $request->input('cookieDomains', []),
            ], $apiKey);

            return response()->json(['data' => $this->transformer->profile($dto)], 201);
        });
    }

    public function deleteProfile(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $this->profileService->deleteProfile($id, $apiKey);

            return response()->json(null, 204);
        });
    }

    public function listBrowsers(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->browserSessionService->listBrowserSessions([
                'status' => $request->input('status'),
                'pageSize' => (int) $request->input('pageSize', 10),
                'pageNumber' => (int) $request->input('pageNumber', 1),
            ], $apiKey);

            return response()->json($this->transformer->paginated($result));
        });
    }

    public function getBrowser(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->browserSessionService->getBrowserSession($id, $apiKey);

            return response()->json(['data' => $this->transformer->browserSession($dto)]);
        });
    }

    public function updateBrowser(Request $request, string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($request, $id) {
            $action = $request->input('action', 'stop');

            if ($action !== 'stop') {
                throw new ApiException('Invalid action', 422, [[
                    'loc' => ['body', 'action'],
                    'msg' => 'invalid stop action',
                    'type' => 'value_error',
                ]]);
            }

            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->browserSessionService->stopBrowserSession($id, $apiKey);

            return response()->json(['data' => $this->transformer->browserSession($dto)]);
        });
    }

    // ─── Tasks ───

    public function listTasks(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->taskService->listTasks([
                'pageSize' => (int) $request->input('pageSize', 10),
                'pageNumber' => (int) $request->input('pageNumber', 1),
                'sessionId' => $request->input('sessionId'),
                'filterBy' => $request->input('filterBy'),
                'after' => $request->input('after'),
                'before' => $request->input('before'),
            ], $apiKey);

            return response()->json($this->transformer->paginated($result));
        });
    }

    public function createTask(Request $request): JsonResponse|Response
    {
        $request->validate([
            'task' => 'required|string',
        ]);

        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->taskService->createTask([
                'task' => $request->input('task'),
                'llm' => $request->input('llm'),
                'startUrl' => $request->input('startUrl'),
                'maxSteps' => (int) $request->input('maxSteps', 30),
                'structuredOutput' => $request->input('structuredOutput'),
                'sessionId' => $request->input('sessionId'),
                'proxyId' => $request->input('proxyId'),
                'proxyUrl' => $request->input('proxyUrl'),
                'proxyCountryCode' => $request->input('proxyCountryCode'),
                'useDedicatedUserProxy' => (bool) $request->boolean('useDedicatedUserProxy'),
                'disableProxy' => (bool) $request->boolean('disableProxy'),
                'organizationId' => (string) ($request->attributes->get('organization')?->id ?? ''),
                'userId' => (string) ($request->user()?->id ?? ''),
                'profileId' => $request->input('profileId'),
                'metadata' => $request->input('metadata') ?? [],
                'secrets' => $request->input('secrets'),
                'allowedDomains' => $request->input('allowedDomains') ?? [],
                'highlightElements' => (bool) $request->input('highlightElements', false),
                'flashMode' => (bool) $request->input('flashMode', false),
                'thinking' => (bool) $request->input('thinking', false),
                'vision' => (bool) $request->input('vision', false),
                'systemPromptExtension' => (string) $request->input('systemPromptExtension', ''),
                'judge' => (bool) $request->input('judge', false),
                'judgeGroundTruth' => $request->input('judgeGroundTruth'),
                'judgeLlm' => $request->input('judgeLlm'),
                'skillIds' => $request->input('skillIds'),
            ], $apiKey);

            return response()->json(['data' => $this->transformer->task($dto)]);
        });
    }

    public function createTasksBulk(Request $request): JsonResponse|Response
    {
        $request->validate([
            'sessionId' => 'nullable|uuid',
            'webhook' => 'nullable|array',
            'webhook.url' => 'nullable|url',
            'webhook.authToken' => 'nullable|string',
            'scheduledAt' => 'prohibited',
            'tasks' => 'required|array|min:1|max:50',
            'tasks.*.task' => 'required|string',
            'tasks.*.order' => 'nullable|integer|min:1',
            'tasks.*.scheduledAt' => 'prohibited',
            'tasks.*.llm' => 'nullable|string',
            'tasks.*.startUrl' => 'nullable|string',
            'tasks.*.maxSteps' => 'nullable|integer|min:1',
            'tasks.*.structuredOutput' => 'nullable|string',
            'tasks.*.metadata' => 'nullable|array',
            'tasks.*.secrets' => 'nullable|array',
            'tasks.*.allowedDomains' => 'nullable|array',
            'tasks.*.highlightElements' => 'nullable|boolean',
            'tasks.*.flashMode' => 'nullable|boolean',
            'tasks.*.thinking' => 'nullable|boolean',
            'tasks.*.vision' => 'nullable|boolean',
            'tasks.*.systemPromptExtension' => 'nullable|string',
            'tasks.*.judge' => 'nullable|boolean',
            'tasks.*.judgeGroundTruth' => 'nullable|string',
            'tasks.*.judgeLlm' => 'nullable|string',
            'tasks.*.skillIds' => 'nullable|array',
        ]);

        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $payload = $request->only([
                'sessionId',
                'webhook',
                'tasks',
            ]);

            $result = $this->taskService->createTasksBulk($payload, $apiKey);

            return response()->json([
                'batchId' => $result['batchId'],
                'sessionId' => $result['sessionId'],
                'submittedCount' => $result['submittedCount'],
                'data' => array_map(
                    fn($dto) => $this->transformer->task($dto),
                    $result['tasks']
                ),
            ], 202);
        });
    }

    public function getTask(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->taskService->getTask($id, $apiKey);

            return response()->json(['data' => $this->transformer->task($dto)]);
        });
    }

    public function updateTask(Request $request, string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($request, $id) {
            $action = $request->input('action', 'stop');
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->taskService->stopTask($id, (string) $action, $apiKey);

            return response()->json(['data' => $this->transformer->task($dto)]);
        });
    }

    public function getTaskLogs(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $logs = $this->taskService->getTaskLogs($id, $apiKey);
            $payload = json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return response($payload ?: '[]', 200)->header('Content-Type', 'text/plain');
        });
    }

    // ─── Secrets ───

    public function listSecrets(Request $request): JsonResponse|Response
    {
        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $result = $this->secretService->listSecrets([
                'pageSize' => (int) $request->input('pageSize', 25),
                'pageNumber' => (int) $request->input('pageNumber', 1),
                'profileId' => $request->input('profileId'),
                'key' => $request->input('key'),
            ], $apiKey);

            return response()->json($this->transformer->paginated($result));
        });
    }

    public function createSecret(Request $request): JsonResponse|Response
    {
        $request->validate([
            'profileId' => 'nullable|uuid',
            'key' => 'required|string|max:120',
            'value' => 'required|string',
            'description' => 'nullable|string|max:255',
        ]);

        return $this->proxy(function () use ($request) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->secretService->createSecret([
                'profileId' => $request->input('profileId'),
                'key' => $request->input('key'),
                'value' => $request->input('value'),
                'description' => $request->input('description'),
            ], $apiKey);

            return response()->json(['data' => $this->transformer->secret($dto)], 201);
        });
    }

    public function getSecret(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->secretService->getSecret($id, $apiKey);

            return response()->json(['data' => $this->transformer->secret($dto)]);
        });
    }

    public function updateSecret(Request $request, string $id): JsonResponse|Response
    {
        $request->validate([
            'profileId' => 'nullable|uuid',
            'key' => 'nullable|string|max:120',
            'value' => 'nullable|string',
            'description' => 'nullable|string|max:255',
        ]);

        return $this->proxy(function () use ($request, $id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $dto = $this->secretService->updateSecret($id, array_filter([
                'profileId' => $request->input('profileId'),
                'key' => $request->input('key'),
                'value' => $request->input('value'),
                'description' => $request->input('description'),
            ], fn ($value) => $value !== null), $apiKey);

            return response()->json(['data' => $this->transformer->secret($dto)]);
        });
    }

    public function deleteSecret(string $id): JsonResponse|Response
    {
        return $this->proxy(function () use ($id) {
            $apiKey = $this->apiKeyService->resolveLocalServiceKey();
            $this->secretService->deleteSecret($id, $apiKey);

            return response()->json(null, 204);
        });
    }
}
