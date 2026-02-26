<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduledPost;
use App\Models\ScheduledPostAccount;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

class ScheduledPostController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('viewAny', [ScheduledPost::class, $organization]);

        $query = ScheduledPost::with(['accounts', 'creator:id,name'])
            ->where('organization_id', $organization->id);
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from')) {
            $query->where('scheduled_for', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('scheduled_for', '<=', $to);
        }
        $query->orderBy($request->input('sort', 'scheduled_for'), $request->input('order', 'asc'));
        return response()->json($query->paginate((int)$request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [ScheduledPost::class, $organization]);
        $data = $request->validate([
            'caption' => 'required|string',
            // 'media_urls' => 'required|array|min:1',
            // 'media_urls.*' => 'url|max:2000',
            'scheduled_for' => 'required|date',
            'timezone' => 'required|string',
            // Make accounts optional; attach if provided
            'account_ids' => 'sometimes|array|min:1',
            'account_ids.*' => 'integer|exists:social_accounts,id',
            'project_id' => 'sometimes|nullable|exists:projects,id',
        ]);
        // Ensure accounts belong to organization (only if provided)
        $accountIds = [];
        if (array_key_exists('account_ids', $data)) {
            $accountIds = SocialAccount::whereIn('id', $data['account_ids'])
                ->where('organization_id', $organization->id)
                ->pluck('id')
                ->all();
            if (count($accountIds) !== count($data['account_ids'])) {
                return response()->json(['message' => 'One or more accounts not in organization'], 422);
            }
        }
        $post = ScheduledPost::create([
            'organization_id' => $organization->id,
            'project_id' => $data['project_id'] ?? null,
            'created_by' => $request->user()->id,
            'caption' => $data['caption'],
            'media_urls' => $data['media_urls'] ?? [],
            'scheduled_for' => $data['scheduled_for'],
            'timezone' => $data['timezone'],
            'status' => 'scheduled',
        ]);
        if (!empty($accountIds)) {
            foreach ($accountIds as $aid) {
                ScheduledPostAccount::create([
                    'scheduled_post_id' => $post->id,
                    'social_account_id' => $aid,
                    'status' => 'pending',
                ]);
            }
        }
        return response()->json($post->load('accounts'), 201);
    }

    public function show(Request $request, $id)
    {
        $post = ScheduledPost::with(['accounts.socialAccount', 'creator'])->findOrFail($id);
        $this->authorize('view', $post);
        return response()->json($post);
    }

    public function update(Request $request, $id)
    {
        $post = ScheduledPost::with('accounts')->findOrFail($id);
        $this->authorize('update', $post);
        $data = $request->validate([
            'caption' => 'sometimes|string',
            'media_urls' => 'sometimes|array',
            'media_urls.*' => 'url|max:2000',
            'scheduled_for' => 'sometimes|date',
            'timezone' => 'sometimes|string',
            'status' => 'sometimes|in:scheduled,publishing,published,failed,cancelled',
            'account_ids' => 'sometimes|array|min:1',
            'account_ids.*' => 'integer|exists:social_accounts,id',
        ]);
        $post->update($data);
        if (array_key_exists('account_ids', $data)) {
            $organizationId = $post->organization_id;
            $accountIds = SocialAccount::whereIn('id', $data['account_ids'])->where('organization_id', $organizationId)->pluck('id')->all();
            // Sync accounts: delete missing, add new
            $existing = $post->accounts->pluck('social_account_id')->all();
            $toAdd = array_diff($accountIds, $existing);
            $toRemove = array_diff($existing, $accountIds);
            if ($toRemove) {
                ScheduledPostAccount::where('scheduled_post_id', $post->id)->whereIn('social_account_id', $toRemove)->delete();
            }
            foreach ($toAdd as $aid) {
                ScheduledPostAccount::create([
                    'scheduled_post_id' => $post->id,
                    'social_account_id' => $aid,
                    'status' => 'pending',
                ]);
            }
        }
        return response()->json($post->load('accounts'));
    }

    public function cancel(Request $request, $id)
    {
        $post = ScheduledPost::findOrFail($id);
        $this->authorize('cancel', $post);
        $post->update(['status' => 'cancelled']);
        ScheduledPostAccount::where('scheduled_post_id', $post->id)->update(['status' => 'pending']);
        return response()->json($post);
    }

    public function destroy(Request $request, $id)
    {
        $post = ScheduledPost::findOrFail($id);
        $this->authorize('delete', $post);
        $post->delete();
        return response()->json(null, 204);
    }

    public function publishNow(Request $request, $id)
    {
        $post = ScheduledPost::with('accounts')->findOrFail($id);
        $this->authorize('update', $post);
        // Simulate immediate publish
        $post->update(['status' => 'publishing']);
        ScheduledPostAccount::where('scheduled_post_id', $post->id)->update([
            'status' => 'published',
            'published_at' => now(),
            'error_message' => null,
        ]);
        $post->update(['status' => 'published', 'published_at' => now()]);
        return response()->json($post->load('accounts'));
    }
}
