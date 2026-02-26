<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RebuildVoiceProfileJob;
use App\Models\IngestionSource;
use App\Models\VoiceProfile;
use App\Models\VoiceProfilePost;
use App\Services\Voice\VoiceProfileBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LaundryOS\SocialWatcher\Models\ContentNode;

class VoiceProfileController extends Controller
{
    private function validateUuidOrBadRequest(string $value, string $field = 'id')
    {
        if (!Str::isUuid($value)) {
            $message = 'The ' . $field . ' must be a valid UUID.';
            
            // Provide helpful hint if frontend passed 'undefined'
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

    public function index(Request $request)
    {
        $org = $request->attributes->get('organization');
        $category = $request->query('category'); // 'post' or 'comment'
        $includePublic = $request->query('include_public', 'true') === 'true';
        
        $query = VoiceProfile::query()
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at');

        // Filter by organization OR include public community profiles
        if ($includePublic) {
            $query->where(function ($q) use ($org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(function ($q2) {
                        $q2->where('is_public', true)
                            ->whereNull('organization_id');
                    });
            });
        } else {
            $query->where('organization_id', $org->id);
        }

        // Filter by category if specified
        if ($category === 'comment') {
            $query->where(function ($q) {
                $q->where('category', 'comment')
                    ->orWhere('type', VoiceProfile::TYPE_COMMENTER);
            });
        } elseif ($category === 'post') {
            $query->where(function ($q) {
                $q->where('category', 'post')
                    ->orWhere(function ($q2) {
                        $q2->whereIn('type', [VoiceProfile::TYPE_INFERRED, VoiceProfile::TYPE_DESIGNED])
                            ->where(function ($q3) {
                                $q3->whereNull('category')
                                    ->orWhere('category', '!=', 'comment');
                            });
                    });
            });
        }

        $list = $query->get([
            'id', 'name', 'organization_id', 'user_id', 'type', 'category',
            'confidence', 'sample_size', 'updated_at', 'traits', 'traits_preview',
            'is_default', 'is_public', 'status'
        ]);

        return response()->json(['data' => $list]);
    }

    public function show(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['data' => $profile]);
    }

    public function update(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['nullable','string','max:255'],
            'traits' => ['nullable','array'],
            'confidence' => ['nullable','numeric','min:0','max:1'],
            'sample_size' => ['nullable','integer','min:0'],
            'is_default' => ['sometimes','boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $profile->name = $data['name'];
        }
        if (array_key_exists('traits', $data)) {
            $profile->traits = $data['traits'];
            $profile->refreshTraitsPreview();
        }
        if (array_key_exists('confidence', $data)) {
            $profile->confidence = (float) $data['confidence'];
        }
        if (array_key_exists('sample_size', $data)) {
            $profile->sample_size = (int) $data['sample_size'];
        }

        // Handle default toggling explicitly and enforce single default per org
        if (array_key_exists('is_default', $data)) {
            $makeDefault = (bool) $data['is_default'];
            if ($makeDefault) {
                // Unset others within the same org (non-deleted)
                VoiceProfile::query()
                    ->where('organization_id', $org->id)
                    ->where('id', '!=', $profile->id)
                    ->whereNull('deleted_at')
                    ->update(['is_default' => false]);
                $profile->is_default = true;
            } else {
                $profile->is_default = false;
            }
        }

        $profile->updated_at = now();
        $profile->save();

        return response()->json(['data' => $profile]);
    }

    public function store(Request $request)
    {
        $org = $request->attributes->get('organization');
        $user = $request->user();

        $data = $request->validate([
            'name' => ['nullable','string','max:255'],
            'type' => ['nullable','string','in:inferred,designed'],
            'traits' => ['nullable','array'],
        ]);

        $type = $data['type'] ?? 'inferred';
        
        $profile = new VoiceProfile();
        $profile->organization_id = $org->id;
        $profile->user_id = $user->id;
        $profile->type = $type;
        $profile->name = $data['name'] ?? null;
        
        // Designed voices can be created with traits immediately
        if ($type === 'designed' && !empty($data['traits'])) {
            $profile->traits = $data['traits'];
            $profile->traits_schema_version = '2.0';
            $profile->refreshTraitsPreview();
            $profile->refreshStylePreview();
            $profile->confidence = 0.8; // Designed voices have fixed confidence
            $profile->sample_size = 0;
            $profile->status = 'ready';
        } else {
            $profile->traits = null;
            $profile->traits_preview = null;
            $profile->confidence = 0.0;
            $profile->sample_size = 0;
            $profile->status = null;
        }
        
        $profile->updated_at = now();
        $profile->save();

        return response()->json(['data' => $profile], 201);
    }

    public function attachPost(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Block attaching posts to designed voices
        if ($profile->isDesigned()) {
            return response()->json([
                'message' => 'Cannot attach posts to designed voice profiles',
            ], 400);
        }

        $data = $request->validate([
            'content_node_id' => ['required','string'],
            'weight' => ['nullable','numeric','min:0','max:999.99'],
            'locked' => ['nullable','boolean'],
        ]);

        // Ensure the referenced content exists
        $content = ContentNode::query()->where('id', $data['content_node_id'])->first();
        if (!$content) {
            return response()->json(['message' => 'content_node_id not found'], 404);
        }

        VoiceProfilePost::query()->updateOrCreate(
            [
                'voice_profile_id' => $profile->id,
                'content_node_id' => $data['content_node_id'],
            ],
            [
                'source_type' => $content->platform ?? null,
                'weight' => $data['weight'] ?? null,
                'locked' => (bool) ($data['locked'] ?? false),
            ]
        );

        $profile->status = 'needs_rebuild';
        $profile->updated_at = now();
        $profile->save();

        return response()->json(['message' => 'attached']);
    }

    public function listAttachedPosts(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        
        // Verify profile exists and belongs to organization
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$profile) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Voice profile not found',
                'status' => 404
            ], 404);
        }

        // Validate query parameters
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $perPage = min((int)($validated['per_page'] ?? 50), 1000);
        $includeMetadata = filter_var($validated['include_metadata'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Build query
        $query = VoiceProfilePost::query()
            ->where('voice_profile_id', $profile->id)
            ->orderBy('created_at', 'desc');

        // Optionally eager load content node
        if ($includeMetadata) {
            $query->with(['contentNode' => function ($q) {
                $q->select([
                    'id',
                    'platform',
                    'text',
                    'title',
                    'author_name',
                    'author_username',
                    'like_count',
                    'comment_count',
                    'share_count',
                    'view_count',
                    'published_at',
                    'metadata'
                ]);
            }]);
        }

        // Paginate
        $paginated = $query->paginate($perPage);

        // Transform data
        $data = $paginated->map(function ($post) use ($includeMetadata) {
            $item = [
                'content_node_id' => $post->content_node_id,
                'weight' => $post->weight ?? 1.0,
                'locked' => (bool)$post->locked,
                'attached_at' => $post->created_at?->toIso8601String(),
            ];

            if ($includeMetadata && $post->contentNode) {
                $content = $post->contentNode;
                $item['content_node'] = [
                    'id' => $content->id,
                    'platform' => $content->platform,
                    'content_text' => $content->text ?? $content->title,
                    'author_name' => $content->author_name,
                    'author_username' => $content->author_username,
                    'like_count' => $content->like_count,
                    'comment_count' => $content->comment_count,
                    'share_count' => $content->share_count,
                    'view_count' => $content->view_count,
                    'published_at' => $content->published_at?->toIso8601String(),
                ];
            }

            return $item;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function detachPost(Request $request, string $id, string $contentNodeId)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }
        if ($res = $this->validateUuidOrBadRequest($contentNodeId, 'contentNodeId')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();

        // Block detaching posts from designed voices
        if ($profile->isDesigned()) {
            return response()->json([
                'message' => 'Cannot detach posts from designed voice profiles',
            ], 400);
        }

        VoiceProfilePost::query()
            ->where('voice_profile_id', $profile->id)
            ->where('content_node_id', $contentNodeId)
            ->delete();

        $profile->status = 'needs_rebuild';
        $profile->updated_at = now();
        $profile->save();

        return response()->json(['message' => 'detached']);
    }

    public function batchAttachPosts(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');

        $profile = VoiceProfile::query()->where('id', $id)->whereNull('deleted_at')->first();
        if (!$profile) {
            return response()->json(['message' => 'Voice profile not found'], 404);
        }
        if ($profile->organization_id !== $org->id) {
            return response()->json(['message' => 'You do not have permission to modify this voice profile'], 403);
        }

        // Block batch attach for designed voices
        if ($profile->isDesigned()) {
            return response()->json([
                'message' => 'Cannot attach posts to designed voice profiles',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'posts' => ['required', 'array', 'min:1', 'max:100'],
            'posts.*.content_node_id' => ['required', 'uuid'],
            'posts.*.weight' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'posts.*.locked' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        /** @var array{posts: array<int,array{content_node_id:string,weight?:mixed,locked?:mixed}>} $data */
        $data = $validator->validated();
        $posts = array_values($data['posts'] ?? []);
        $requestedIds = array_values(array_map(fn ($p) => (string) ($p['content_node_id'] ?? ''), $posts));
        $uniqueRequestedIds = array_values(array_unique(array_filter($requestedIds)));

        $contentNodeTable = (new ContentNode())->getTable();
        $contents = ContentNode::query()
            ->whereIn($contentNodeTable . '.id', $uniqueRequestedIds)
            ->get([$contentNodeTable . '.id', $contentNodeTable . '.platform'])
            ->keyBy('id');

        $alreadyAttached = VoiceProfilePost::query()
            ->where('voice_profile_id', $profile->id)
            ->whereIn('content_node_id', $uniqueRequestedIds)
            ->pluck('content_node_id')
            ->all();
        $alreadySet = array_fill_keys($alreadyAttached, true);

        $skipped = [];
        $rows = [];
        $seenInRequest = [];

        foreach ($posts as $p) {
            $contentNodeId = (string) ($p['content_node_id'] ?? '');
            if ($contentNodeId === '') {
                continue;
            }

            if (isset($seenInRequest[$contentNodeId])) {
                $skipped[] = [
                    'content_node_id' => $contentNodeId,
                    'reason' => 'Already attached to profile',
                ];
                continue;
            }
            $seenInRequest[$contentNodeId] = true;

            $content = $contents->get($contentNodeId);

            if (!$content) {
                $skipped[] = [
                    'content_node_id' => $contentNodeId,
                    'reason' => 'Post not found',
                ];
                continue;
            }
            if (isset($alreadySet[$contentNodeId])) {
                $skipped[] = [
                    'content_node_id' => $contentNodeId,
                    'reason' => 'Already attached to profile',
                ];
                continue;
            }

            $weight = array_key_exists('weight', $p) ? (float) $p['weight'] : 1.0;
            $weight = max(0.0, min(1.0, $weight));

            $rows[] = [
                'id' => (string) Str::uuid(),
                'voice_profile_id' => $profile->id,
                'content_node_id' => $contentNodeId,
                'source_type' => $content->platform ?? null,
                'weight' => round($weight, 2),
                'locked' => (bool) ($p['locked'] ?? false),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $attachedCount = 0;
        DB::transaction(function () use (&$attachedCount, $rows, $profile) {
            if (!empty($rows)) {
                $attachedCount = (int) DB::table('voice_profile_posts')->insertOrIgnore($rows);
            }
            $profile->status = 'needs_rebuild';
            $profile->updated_at = now();
            $profile->save();
        });

        $totalTraining = VoiceProfilePost::query()->where('voice_profile_id', $profile->id)->count();

        return response()->json([
            'message' => 'Successfully attached ' . $attachedCount . ' posts to voice profile',
            'profile_id' => $profile->id,
            'attached_count' => $attachedCount,
            'skipped_count' => count($skipped),
            'skipped_posts' => $skipped,
            'total_training_posts' => $totalTraining,
        ]);
    }

    public function autoSelectPosts(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');

        $profile = VoiceProfile::query()->where('id', $id)->whereNull('deleted_at')->first();
        if (!$profile) {
            return response()->json(['message' => 'Voice profile not found'], 404);
        }
        if ($profile->organization_id !== $org->id) {
            return response()->json(['message' => 'You do not have permission to modify this voice profile'], 403);
        }

        // Block auto-select for designed voices
        if ($profile->isDesigned()) {
            return response()->json([
                'message' => 'Cannot auto-select posts for designed voice profiles',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'source_id' => ['nullable', 'integer'],
            'min_engagement_score' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'exclude_replies' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['engagement_score', 'velocity_score', 'likes', 'comments', 'views'])],
            'replace_existing' => ['nullable', 'boolean'],
            'preserve_locked' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();
        $limit = (int) ($data['limit'] ?? 20);
        $sourceId = $data['source_id'] ?? null;
        $minEngagement = $data['min_engagement_score'] ?? null;
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;
        $excludeReplies = array_key_exists('exclude_replies', $data) ? (bool) $data['exclude_replies'] : true;
        $sortBy = (string) ($data['sort_by'] ?? 'like_count');
        $replaceExisting = (bool) ($data['replace_existing'] ?? false);
        $preserveLocked = array_key_exists('preserve_locked', $data) ? (bool) $data['preserve_locked'] : true;

        $contentNodeTable = (new ContentNode())->getTable();

        $removedCount = 0;
        $preservedLockedCount = 0;
        $selected = collect();
        $insertedCount = 0;

        DB::transaction(function () use (
            $profile,
            $limit,
            $sourceId,
            $minEngagement,
            $startDate,
            $endDate,
            $excludeReplies,
            $sortBy,
            $replaceExisting,
            $preserveLocked,
            $contentNodeTable,
            &$removedCount,
            &$preservedLockedCount,
            &$selected,
            &$insertedCount
        ) {
            if ($replaceExisting) {
                if ($preserveLocked) {
                    $preservedLockedCount = (int) VoiceProfilePost::query()
                        ->where('voice_profile_id', $profile->id)
                        ->where('locked', true)
                        ->count();
                }

                $deleteQ = VoiceProfilePost::query()->where('voice_profile_id', $profile->id);
                if ($preserveLocked) {
                    $deleteQ->where('locked', false);
                }
                $removedCount = (int) $deleteQ->count();
                $deleteQ->delete();
            }

            $existingIds = VoiceProfilePost::query()
                ->where('voice_profile_id', $profile->id)
                ->pluck('content_node_id')
                ->all();
            $existingSet = array_fill_keys($existingIds, true);

            $q = ContentNode::query();
            if ($sourceId !== null) {
                $q->where($contentNodeTable . '.source_id', (int) $sourceId);
            }
            if ($minEngagement !== null) {
                $q->where($contentNodeTable . '.like_count', '>=', (int) $minEngagement);
            }
            if ($startDate) {
                $q->where($contentNodeTable . '.published_at', '>=', $startDate . ' 00:00:00');
            }
            if ($endDate) {
                $q->where($contentNodeTable . '.published_at', '<=', $endDate . ' 23:59:59');
            }
            if ($excludeReplies) {
                $q->where(function ($qq) {
                    $qq->whereNull('metadata->is_reply')
                        ->orWhere('metadata->is_reply', false);
                });
            }

            $q->orderByDesc($sortBy)->orderByDesc($contentNodeTable . '.published_at');

            $selected = $q->limit($limit)->get([
                $contentNodeTable . '.id',
                $contentNodeTable . '.platform',
                $contentNodeTable . '.like_count',
                $contentNodeTable . '.view_count',
            ]);

            $rows = [];
            foreach ($selected as $row) {
                $nid = (string) $row->id;
                if (isset($existingSet[$nid])) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'voice_profile_id' => $profile->id,
                    'content_node_id' => $nid,
                    'source_type' => $row->platform ?? null,
                    'weight' => 1.0,
                    'locked' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($rows)) {
                $insertedCount = (int) DB::table('voice_profile_posts')->insertOrIgnore($rows);
            }

            $profile->status = 'needs_rebuild';
            $profile->updated_at = now();
            $profile->save();
        });

        $totalTraining = VoiceProfilePost::query()->where('voice_profile_id', $profile->id)->count();
        $selectedPostsOut = $selected
            ->take(20)
            ->map(function ($row) {
                return [
                    'content_node_id' => (string) $row->id,
                    'like_count' => $row->like_count !== null ? (int) $row->like_count : null,
                    'view_count' => $row->view_count !== null ? (int) $row->view_count : null,
                    'platform' => $row->platform ?? null,
                ];
            })
            ->values()
            ->all();

        $criteria = [
            'limit' => $limit,
            'sort_by' => $sortBy,
            'min_engagement_score' => $minEngagement,
            'date_range' => ($startDate && $endDate) ? ($startDate . ' to ' . $endDate) : null,
        ];

        return response()->json([
            'message' => 'Successfully auto-selected ' . $insertedCount . ' top posts',
            'profile_id' => $profile->id,
            'selected_count' => $insertedCount,
            'removed_count' => $removedCount,
            'preserved_locked_count' => $preservedLockedCount,
            'total_training_posts' => $totalTraining,
            'selection_criteria' => $criteria,
            'selected_posts' => $selectedPostsOut,
        ]);
    }

    public function rebuild(Request $request, string $id)
    {
        if ($res = $this->validateUuidOrBadRequest($id, 'id')) {
            return $res;
        }

        $org = $request->attributes->get('organization');
        $profile = VoiceProfile::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Block rebuild for designed voices
        if ($profile->isDesigned()) {
            return response()->json([
                'message' => 'Cannot rebuild designed voice profiles',
            ], 400);
        }

        $filters = $request->validate([
            'source_id' => ['nullable','integer'],
            'min_engagement' => ['nullable','numeric'],
            'start_date' => ['nullable','date'],
            'end_date' => ['nullable','date'],
            'exclude_replies' => ['nullable','boolean'],
            'limit' => ['nullable','integer','min:1','max:500'],
            'schema_version' => ['nullable','string','in:1.0,2.0'],
        ]);

        // Set status to queued
        $profile->status = 'queued';
        $profile->updated_at = now();
        $profile->save();

        // Dispatch the rebuild job
        RebuildVoiceProfileJob::dispatch($profile->id, $filters);

        return response()->json([
            'message' => 'Voice profile rebuild queued',
            'profile' => [
                'id' => $profile->id,
                'status' => 'queued',
            ],
        ], 202);
    }
}
