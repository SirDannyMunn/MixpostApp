<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduledPost;
use App\Models\ScheduledPostAccount;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'event' => 'required|string',
            'scheduled_post_id' => 'sometimes|integer|exists:scheduled_posts,id',
            'scheduled_post_account_id' => 'sometimes|integer|exists:scheduled_post_accounts,id',
            'error' => 'sometimes|nullable|string',
        ]);

        $event = $data['event'];
        if (!empty($data['scheduled_post_account_id'])) {
            $spa = ScheduledPostAccount::findOrFail($data['scheduled_post_account_id']);
            if ($event === 'post.published') {
                $spa->update(['status' => 'published', 'published_at' => now(), 'error_message' => null]);
            } elseif ($event === 'post.failed') {
                $spa->update(['status' => 'failed', 'error_message' => $data['error'] ?? '']);
            }
            $this->refreshScheduledPostStatus($spa->scheduled_post_id);
        } elseif (!empty($data['scheduled_post_id'])) {
            $post = ScheduledPost::findOrFail($data['scheduled_post_id']);
            if ($event === 'post.publishing') {
                $post->update(['status' => 'publishing']);
            } elseif ($event === 'post.published') {
                $post->update(['status' => 'published', 'published_at' => now()]);
            } elseif ($event === 'post.failed') {
                $post->update(['status' => 'failed', 'error_message' => $data['error'] ?? '']);
            }
        }

        return response()->json(['ok' => true]);
    }

    protected function refreshScheduledPostStatus(int $scheduledPostId): void
    {
        $post = ScheduledPost::with('accounts')->find($scheduledPostId);
        if (!$post) return;
        $statuses = $post->accounts->pluck('status');
        if ($statuses->every(fn($s) => $s === 'published')) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($statuses->contains('failed')) {
            $post->update(['status' => 'failed']);
        } elseif ($statuses->contains('publishing')) {
            $post->update(['status' => 'publishing']);
        } else {
            $post->update(['status' => 'scheduled']);
        }
    }
}
