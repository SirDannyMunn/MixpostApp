<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentPlan;
use App\Models\ContentPlanStage;
use App\Services\BlueprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ContentPlanController extends Controller
{
    private BlueprintService $blueprintService;

    public function __construct(BlueprintService $blueprintService)
    {
        $this->blueprintService = $blueprintService;
    }

    private function validateUuidOrBadRequest(string $value, string $field = 'id')
    {
        if (!Str::isUuid($value)) {
            $message = 'The ' . $field . ' must be a valid UUID.';
            
            if ($value === 'undefined' || $value === 'null') {
                $message .= ' The value "' . $value . '" suggests the frontend variable is not initialized.';
            }
            
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    $field => [$message],
                ],
            ], 400);
        }

        return null;
    }

    public function store(Request $request)
    {
        $org = $request->attributes->get('organization');
        
        $validator = Validator::make($request->all(), [
            'plan_type' => 'required|string|max:255',
            'duration_days' => 'required|integer|min:1|max:365',
            'platform' => 'required|string|max:255',
            'goal' => 'nullable|string',
            'audience' => 'nullable|string',
            'voice_profile_id' => 'nullable|uuid|exists:voice_profiles,id',
            'conversation_id' => 'nullable|uuid|exists:ai_canvas_conversations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plan = ContentPlan::create([
            'organization_id' => $org->id,
            'user_id' => $request->user()->id,
            'conversation_id' => $request->input('conversation_id'),
            'plan_type' => $request->input('plan_type'),
            'duration_days' => $request->input('duration_days'),
            'platform' => $request->input('platform'),
            'goal' => $request->input('goal'),
            'audience' => $request->input('audience'),
            'voice_profile_id' => $request->input('voice_profile_id'),
            'status' => 'draft',
        ]);

        // Generate blueprint stages
        try {
            $this->blueprintService->generateStages($plan);
        } catch (\InvalidArgumentException $e) {
            // Clean up plan if blueprint generation fails
            $plan->delete();
            return response()->json([
                'message' => 'Failed to generate plan stages',
                'error' => $e->getMessage(),
            ], 422);
        }

        $plan->load('stages');

        return response()->json([
            'data' => $plan,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        
        $plan = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->with(['stages.posts'])
            ->firstOrFail();

        return response()->json([
            'data' => $plan,
        ]);
    }

    public function index(Request $request)
    {
        $org = $request->attributes->get('organization');
        
        $plans = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->with(['stages'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $plans,
        ]);
    }

    public function confirm(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        
        $plan = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();

        // Only allow confirming draft plans
        if ($plan->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft plans can be confirmed',
                'current_status' => $plan->status,
            ], 400);
        }

        // Update plan status
        $plan->update(['status' => 'confirmed']);

        // Queue generation job (US-006)
        dispatch(new \App\Jobs\GenerateContentPlanJob($plan->id));

        return response()->json([
            'data' => $plan,
            'message' => 'Plan confirmed successfully',
        ]);
    }

    public function regenerateStages(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        
        $plan = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();

        // Only allow regenerating draft plans
        if ($plan->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft plans can have stages regenerated',
                'current_status' => $plan->status,
            ], 400);
        }

        try {
            $this->blueprintService->generateStages($plan);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Failed to regenerate plan stages',
                'error' => $e->getMessage(),
            ], 422);
        }

        $plan->load('stages');

        return response()->json([
            'data' => $plan,
            'message' => 'Stages regenerated successfully',
        ]);
    }

    public function getByConversation(Request $request, string $conversationId)
    {
        if ($res = $this->validateUuidOrBadRequest($conversationId, 'conversation_id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        
        $plan = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->where('conversation_id', $conversationId)
            ->with(['stages.posts'])
            ->first();

        if (!$plan) {
            return response()->json([
                'message' => 'No content plan found for this conversation',
            ], 404);
        }

        return response()->json([
            'data' => $plan,
        ]);
    }

    public function updatePost(Request $request, string $planId, string $postId)
    {
        if ($res = $this->validateUuidOrBadRequest($planId, 'plan_id')) {
            return $res;
        }

        if ($res = $this->validateUuidOrBadRequest($postId, 'post_id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');

        $validator = Validator::make($request->all(), [
            'draft_text' => 'sometimes|string',
            'status' => 'sometimes|string|in:draft,approved,scheduled,published,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify the plan belongs to the organization
        $plan = ContentPlan::query()
            ->where('organization_id', $org->id)
            ->where('id', $planId)
            ->firstOrFail();

        // Find the post through the plan's stages
        $post = ContentPlanPost::query()
            ->where('id', $postId)
            ->where('organization_id', $org->id)
            ->whereHas('stage', function ($query) use ($planId) {
                $query->where('content_plan_id', $planId);
            })
            ->firstOrFail();

        // Update the post
        $post->update($request->only(['draft_text', 'status']));

        return response()->json([
            'data' => $post,
            'message' => 'Post updated successfully',
        ]);
    }
}
