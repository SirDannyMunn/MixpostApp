<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractSwipeStructureJob;
use App\Models\SwipeItem;
use Illuminate\Http\Request;

class SwipeItemController extends Controller
{
    // GET /api/v1/swipe-items
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $query = SwipeItem::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('raw_text', 'like', "%$search%")
                  ->orWhere('author_handle', 'like', "%$search%")
                  ->orWhere('saved_reason', 'like', "%$search%");
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function ($s) {
            $label = (string) ($s->saved_reason ?: mb_substr((string) $s->raw_text, 0, 80));
            $handle = (string) ($s->author_handle ?? '');
            if ($handle !== '' && $handle[0] !== '@') { $handle = '@' . $handle; }
            return [
                'id' => (string) $s->id,
                'label' => $label,
                'author_handle' => $handle,
            ];
        });

        return response()->json($page);
    }
    // POST /api/v1/swipe-items
    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'platform' => 'required|string|in:linkedin,x,reddit,blog,newsletter,other',
            'raw_text' => 'required|string|min:50|max:5000',
            'source_url' => 'sometimes|nullable|url',
            'author_handle' => 'sometimes|nullable|string|max:191',
            'engagement' => 'sometimes|array',
            'saved_reason' => 'sometimes|nullable|string',
        ]);

        $hash = hash('sha256', $data['raw_text']);
        $swipe = SwipeItem::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'platform' => $data['platform'],
            'source_url' => $data['source_url'] ?? null,
            'author_handle' => $data['author_handle'] ?? null,
            'raw_text' => $data['raw_text'],
            'raw_text_sha256' => $hash,
            'engagement' => $data['engagement'] ?? null,
            'saved_reason' => $data['saved_reason'] ?? null,
            'created_at' => now(),
        ]);

        dispatch(new ExtractSwipeStructureJob($swipe->id));

        return response()->json(['id' => $swipe->id, 'status' => 'queued']);
    }
}
