<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use App\Models\AiCanvasConversation;
use App\Services\ContentPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContentPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_initializes_correctly()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
        ]);

        $planner = app(ContentPlannerService::class);
        $result = $planner->initializePlanner($conversation);

        $this->assertEquals('asking', $result['status']);
        $this->assertArrayHasKey('question', $result);
        $this->assertEquals(0, $result['question_index']);

        $conversation->refresh();
        $this->assertEquals('content_planner', $conversation->planner_mode);
        $this->assertNotNull($conversation->planner_state);
    }

    public function test_planner_processes_valid_answer()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
            'planner_mode' => 'content_planner',
            'planner_state' => [
                'question_index' => 0,
                'answers' => [],
                'status' => 'collecting',
            ],
        ]);

        $planner = app(ContentPlannerService::class);
        $result = $planner->processAnswer($conversation, 'build_in_public');

        $this->assertEquals('asking', $result['status']);
        $this->assertEquals(1, $result['question_index']);

        $conversation->refresh();
        $this->assertEquals('build_in_public', $conversation->planner_state['answers']['plan_type']);
    }

    public function test_planner_rejects_invalid_answer()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
            'planner_mode' => 'content_planner',
            'planner_state' => [
                'question_index' => 0,
                'answers' => [],
                'status' => 'collecting',
            ],
        ]);

        $planner = app(ContentPlannerService::class);
        $result = $planner->processAnswer($conversation, 'invalid_plan_type');

        $this->assertEquals('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_planner_completes_all_questions()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
            'planner_mode' => 'content_planner',
            'planner_state' => [
                'question_index' => 0,
                'answers' => [],
                'status' => 'collecting',
            ],
        ]);

        $planner = app(ContentPlannerService::class);

        $answers = [
            'build_in_public',
            '7',
            'twitter',
            'Build awareness for my SaaS',
            'indie hackers and founders',
            'inferred',
        ];

        foreach ($answers as $answer) {
            $result = $planner->processAnswer($conversation, $answer);
            $conversation->refresh();
        }

        $this->assertEquals('review', $result['status']);
        $this->assertArrayHasKey('answers', $result);
        $this->assertCount(6, $result['answers']);
    }

    public function test_planner_handles_confirm_action()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
            'planner_mode' => 'content_planner',
            'planner_state' => [
                'question_index' => 6,
                'answers' => [
                    'plan_type' => 'build_in_public',
                    'duration_days' => '7',
                    'platform' => 'twitter',
                    'goal' => 'Build awareness',
                    'audience' => 'founders',
                    'voice_mode' => 'inferred',
                ],
                'status' => 'review',
            ],
        ]);

        $planner = app(ContentPlannerService::class);
        $result = $planner->processAnswer($conversation, 'confirm');

        $this->assertEquals('confirmed', $result['status']);
        $this->assertEquals('confirm', $result['action']);
        $this->assertArrayHasKey('answers', $result);
    }

    public function test_planner_handles_edit_action()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $conversation = AiCanvasConversation::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'message_count' => 0,
            'planner_mode' => 'content_planner',
            'planner_state' => [
                'question_index' => 6,
                'answers' => [
                    'plan_type' => 'build_in_public',
                    'duration_days' => '7',
                    'platform' => 'twitter',
                    'goal' => 'Build awareness',
                    'audience' => 'founders',
                    'voice_mode' => 'inferred',
                ],
                'status' => 'review',
            ],
        ]);

        $planner = app(ContentPlannerService::class);
        $result = $planner->processAnswer($conversation, 'edit');

        $this->assertEquals('editing', $result['status']);
        $this->assertEquals(0, $result['question_index']);
        
        $conversation->refresh();
        $this->assertEquals('collecting', $conversation->planner_state['status']);
        $this->assertEquals(0, $conversation->planner_state['question_index']);
    }
}
