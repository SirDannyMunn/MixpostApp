<?php

namespace Tests\Unit\Services;

use App\Models\ContentPlan;
use App\Services\BlueprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueprintServiceTest extends TestCase
{
    use RefreshDatabase;

    private BlueprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlueprintService();
    }

    public function test_generates_7_day_build_in_public_blueprint()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 7,
            'status' => 'draft',
        ]);

        $this->service->generateStages($plan);

        $this->assertEquals(7, $plan->stages()->count());
        
        $stages = $plan->stages()->orderBy('day_index')->get();
        
        // Check day 1
        $this->assertEquals(1, $stages[0]->day_index);
        $this->assertEquals('announce', $stages[0]->stage_type);
        $this->assertNotNull($stages[0]->intent);
        $this->assertNotNull($stages[0]->prompt_seed);
        
        // Check day 7
        $this->assertEquals(7, $stages[6]->day_index);
        $this->assertEquals('reflect', $stages[6]->stage_type);
    }

    public function test_generates_14_day_build_in_public_blueprint()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 14,
            'status' => 'draft',
        ]);

        $this->service->generateStages($plan);

        $this->assertEquals(14, $plan->stages()->count());
        
        $stages = $plan->stages()->orderBy('day_index')->get();
        
        // Check day 1
        $this->assertEquals(1, $stages[0]->day_index);
        $this->assertEquals('announce', $stages[0]->stage_type);
        
        // Check day 14
        $this->assertEquals(14, $stages[13]->day_index);
        $this->assertEquals('launch', $stages[13]->stage_type);
    }

    public function test_replaces_existing_stages_on_regenerate()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 7,
            'status' => 'draft',
        ]);

        // First generation
        $this->service->generateStages($plan);
        $firstStageIds = $plan->stages()->pluck('id')->toArray();

        // Regenerate
        $this->service->generateStages($plan);
        $secondStageIds = $plan->stages()->pluck('id')->toArray();

        // Should still have 7 stages
        $this->assertEquals(7, $plan->stages()->count());
        
        // But they should be different records (new IDs)
        $this->assertNotEquals($firstStageIds, $secondStageIds);
    }

    public function test_throws_exception_for_confirmed_plans()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 7,
            'status' => 'confirmed',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot regenerate stages for confirmed or ready plans');

        $this->service->generateStages($plan);
    }

    public function test_throws_exception_for_ready_plans()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 7,
            'status' => 'ready',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot regenerate stages for confirmed or ready plans');

        $this->service->generateStages($plan);
    }

    public function test_throws_exception_for_unknown_plan_type()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'unknown_type',
            'duration_days' => 7,
            'status' => 'draft',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plan type: unknown_type');

        $this->service->generateStages($plan);
    }

    public function test_throws_exception_for_unsupported_duration()
    {
        $plan = ContentPlan::factory()->create([
            'plan_type' => 'build_in_public',
            'duration_days' => 10,
            'status' => 'draft',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Build in Public supports 7 or 14 days, got 10');

        $this->service->generateStages($plan);
    }
}
