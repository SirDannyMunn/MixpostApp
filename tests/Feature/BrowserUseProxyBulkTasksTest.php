<?php

namespace Tests\Feature;

use LaundryOS\PhantomBrowseCore\DataTransferObjects\TaskDto;
use LaundryOS\PhantomBrowseCore\Models\ApiKey;
use LaundryOS\PhantomBrowseCore\Services\ApiKeyService;
use LaundryOS\PhantomBrowseCore\Services\TaskService;
use Mockery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
class BrowserUseProxyBulkTasksTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_browser_use_proxy_supports_bulk_task_create(): void
    {
        $this->withoutMiddleware();

        $apiKey = new ApiKey();
        $apiKey->id = 'api-key-1';

        $apiKeyService = Mockery::mock(ApiKeyService::class);
        $apiKeyService->shouldReceive('resolveLocalServiceKey')
            ->once()
            ->andReturn($apiKey);
        $this->app->instance(ApiKeyService::class, $apiKeyService);

        $taskDto = new TaskDto(
            id: 'task-1',
            sessionId: 'sess-1',
            profileId: null,
            llm: 'bu-latest',
            task: 'Open linkedin profile',
            status: 'queued',
            createdAt: now()->toISOString(),
            startedAt: null,
            finishedAt: null,
            metadata: [],
            output: null,
            browserUseVersion: '0.5.0',
            isSuccess: null,
            judgement: null,
            judgeVerdict: null,
            outputJson: null,
            errorCode: null,
            errorMessage: null,
            blockedReason: null,
            executionOrder: 1,
            completionWebhookStatus: 'pending',
        );

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('createTasksBulk')
            ->once()
            ->andReturn([
                'batchId' => 'batch-1',
                'sessionId' => 'sess-1',
                'submittedCount' => 1,
                'tasks' => [$taskDto],
            ]);
        $this->app->instance(TaskService::class, $taskService);

        $response = $this->postJson('/api/v1/browser-use/tasks/bulk', [
            'sessionId' => '11111111-1111-1111-1111-111111111111',
            'webhook' => [
                'url' => 'https://velocity.dev/api/v1/lead-outreach/webhooks/phantombrowse-task-complete',
                'authToken' => 'token',
            ],
            'tasks' => [
                ['task' => 'Open profile', 'order' => 1],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('batchId', 'batch-1')
            ->assertJsonPath('sessionId', 'sess-1')
            ->assertJsonPath('submittedCount', 1)
            ->assertJsonPath('data.0.id', 'task-1')
            ->assertJsonPath('data.0.executionOrder', 1);
    }
}
