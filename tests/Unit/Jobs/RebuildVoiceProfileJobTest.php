<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RebuildVoiceProfileJob;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Voice\VoiceProfileBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RebuildVoiceProfileJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $profileId = Str::uuid()->toString();
        $filters = ['source_id' => 123];

        RebuildVoiceProfileJob::dispatch($profileId, $filters);

        Queue::assertPushed(RebuildVoiceProfileJob::class, function ($job) use ($profileId) {
            return $job->voiceProfileId === $profileId;
        });
    }

    public function test_job_sets_status_to_processing(): void
    {
        $user = User::factory()->create();
        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'name' => 'Test Profile',
            'status' => 'queued',
        ]);

        // Mock the builder service to avoid actual rebuild
        $mockBuilder = $this->createMock(VoiceProfileBuilderService::class);
        $mockBuilder->method('rebuild')->willReturn($profile);

        $this->app->instance(VoiceProfileBuilderService::class, $mockBuilder);

        $job = new RebuildVoiceProfileJob($profile->id);
        $job->handle($mockBuilder);

        $profile->refresh();
        $this->assertEquals('ready', $profile->status);
    }

    public function test_job_sets_status_to_error_on_failure(): void
    {
        $user = User::factory()->create();
        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'name' => 'Test Profile',
            'status' => 'queued',
        ]);

        // Mock the builder to throw an exception
        $mockBuilder = $this->createMock(VoiceProfileBuilderService::class);
        $mockBuilder->method('rebuild')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->app->instance(VoiceProfileBuilderService::class, $mockBuilder);

        $job = new RebuildVoiceProfileJob($profile->id);

        try {
            $job->handle($mockBuilder);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $profile->refresh();
        $this->assertEquals('error', $profile->status);
    }

    public function test_job_handles_missing_profile_gracefully(): void
    {
        $fakeId = Str::uuid()->toString();

        $mockBuilder = $this->createMock(VoiceProfileBuilderService::class);
        $mockBuilder->expects($this->never())->method('rebuild');

        $this->app->instance(VoiceProfileBuilderService::class, $mockBuilder);

        $job = new RebuildVoiceProfileJob($fakeId);
        
        // Should not throw exception
        $job->handle($mockBuilder);
        
        $this->assertTrue(true); // Assert job completes without error
    }
}
