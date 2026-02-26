<?php

namespace Tests\Unit\BrowserUseWorker;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaundryOS\BrowserUseWorker\Data\ControlCommand;
use LaundryOS\BrowserUseWorker\Data\SessionTaskCommand;
use LaundryOS\BrowserUseWorker\Exceptions\InvalidWorkerCommandException;
use LaundryOS\BrowserUseWorker\Services\RedisWorkerCommandBus;
use PHPUnit\Framework\TestCase;

class RedisWorkerCommandBusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container();
        $app->instance('log', new class {
            public function info(string $message, array $context = []): void {}
        });
        Facade::setFacadeApplication($app);
    }

    public function test_enqueue_task_validates_required_fields(): void
    {
        $bus = new RedisWorkerCommandBus();
        $this->expectException(InvalidWorkerCommandException::class);

        $bus->enqueueTask('', '', new SessionTaskCommand(prompt: ''));
    }

    public function test_enqueue_task_writes_to_session_stream_and_ready_set(): void
    {
        $calls = [];
        $bus = new class($calls) extends RedisWorkerCommandBus {
            public function __construct(public array &$calls)
            {
                parent::__construct();
            }
            protected function withRedis(callable $cb): void
            {
                $redis = new class($this->calls) {
                    public function __construct(private array &$calls) {}
                    public function xadd(string $stream, string $id, array $payload): void
                    {
                        $this->calls[] = ['xadd', $stream, $id, $payload];
                    }
                    public function zadd(string $key, float $score, string $member): void
                    {
                        $this->calls[] = ['zadd', $key, $score, $member];
                    }
                };
                $cb($redis, null);
            }
        };
        $bus->enqueueTask(
            's1',
            't1',
            new SessionTaskCommand(
                prompt: 'find something',
                maxSteps: 12,
                timeoutSeconds: 42,
                metadata: ['a' => 1],
                secrets: ['token' => 'redacted'],
                proxyUrl: 'http://user:pass@127.0.0.1:8080',
                proxyId: 'proxy-uuid-1',
            )
        );

        $this->assertCount(2, $calls);
        $this->assertSame('xadd', $calls[0][0]);
        $this->assertSame('pb:session:s1:cmds', $calls[0][1]);
        $this->assertSame('*', $calls[0][2]);
        $this->assertSame('2', $calls[0][3]['schema_version']);
        $this->assertSame('task.run', $calls[0][3]['type']);
        $this->assertSame('s1', $calls[0][3]['session_id']);
        $this->assertSame('t1', $calls[0][3]['task_id']);
        $this->assertSame('find something', $calls[0][3]['prompt']);
        $this->assertArrayHasKey('secrets_json', $calls[0][3]);
        $this->assertSame('http://user:pass@127.0.0.1:8080', $calls[0][3]['proxy_url']);
        $this->assertSame('proxy-uuid-1', $calls[0][3]['proxy_id']);
        $this->assertSame('zadd', $calls[1][0]);
        $this->assertSame('pb:sessions:ready', $calls[1][1]);
        $this->assertSame('s1', $calls[1][3]);
    }

    public function test_send_control_serializes_type_and_action(): void
    {
        $calls = [];
        $bus = new class($calls) extends RedisWorkerCommandBus {
            public function __construct(public array &$calls)
            {
                parent::__construct();
            }
            protected function withRedis(callable $cb): void
            {
                $redis = new class($this->calls) {
                    public function __construct(private array &$calls) {}
                    public function xadd(string $stream, string $id, array $payload): void
                    {
                        $this->calls[] = [$stream, $id, $payload];
                    }
                };
                $cb($redis, null);
            }
        };
        $bus->sendControl('s1', new ControlCommand(type: 'session.terminate', reason: 'manual'));

        $this->assertCount(1, $calls);
        $this->assertSame('pb:runners:control', $calls[0][0]);
        $this->assertSame('*', $calls[0][1]);
        $this->assertSame('session.terminate', $calls[0][2]['type']);
        $this->assertSame('terminate_session', $calls[0][2]['action']);
        $this->assertSame('s1', $calls[0][2]['session_id']);
    }

    public function test_send_control_carries_runner_id_payload_for_runner_terminate(): void
    {
        $calls = [];
        $bus = new class($calls) extends RedisWorkerCommandBus {
            public function __construct(public array &$calls)
            {
                parent::__construct();
            }
            protected function withRedis(callable $cb): void
            {
                $redis = new class($this->calls) {
                    public function __construct(private array &$calls) {}
                    public function xadd(string $stream, string $id, array $payload): void
                    {
                        $this->calls[] = [$stream, $id, $payload];
                    }
                };
                $cb($redis, null);
            }
        };

        $bus->sendControl('', new ControlCommand(type: 'runner.terminate', payload: ['runner_id' => 'runner-123']));

        $this->assertCount(1, $calls);
        $this->assertSame('pb:runners:control', $calls[0][0]);
        $this->assertSame('runner.terminate', $calls[0][2]['type']);
        $this->assertSame('terminate_runner', $calls[0][2]['action']);
        $this->assertArrayHasKey('payload', $calls[0][2]);
        $decoded = json_decode($calls[0][2]['payload'], true);
        $this->assertSame('runner-123', $decoded['runner_id'] ?? null);
    }
}
