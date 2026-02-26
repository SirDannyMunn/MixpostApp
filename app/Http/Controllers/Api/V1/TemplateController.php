<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Template;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $query = Template::with(['folder:id,display_name', 'tags:id,name,color', 'creator:id,name']);

        // If requesting public templates, ignore organization scoping
        if ($request->boolean('is_public')) {
            $query->where('is_public', true);
        } else {
            $query->where('organization_id', $organization->id);
        }

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->input('folder_id'));
        }
        if ($tagId = $request->input('tag_id')) {
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
        }
        if ($type = $request->input('template_type')) {
            $query->where('template_type', $type);
        }
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($request->boolean('is_public')) {
            $query->where('is_public', true);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('description', 'like', "%$search%");
            });
        }

        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $perPage = (int) $request->input('per_page', 20);
        $page = $query->paginate($perPage);

        // Ensure index returns fields needed by context menu: id, label, structure_preview
        $page->getCollection()->transform(function ($t) {
            $label = (string) ($t->name ?? 'Template');
            $structure = (array) ($t->template_data['structure'] ?? []);
            $sections = [];
            if (isset($structure['sections']) && is_array($structure['sections'])) {
                foreach ($structure['sections'] as $s) {
                    $sec = is_string($s) ? $s : (is_array($s) ? ($s['key'] ?? $s['name'] ?? '') : '');
                    $sec = trim((string) $sec);
                    if ($sec !== '') { $sections[] = $sec; }
                }
            }
            $preview = !empty($sections) ? implode(' -> ', $sections) : '';
            // Keep original attributes and append the required keys
            $t->setAttribute('label', $label);
            $t->setAttribute('structure_preview', $preview);
            return $t;
        });

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [Template::class, $organization]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'folder_id' => 'nullable|exists:folders,id',
            'thumbnail_url' => 'nullable|url|max:2000',
            'template_type' => 'required|in:slideshow,post,story,reel,custom,comment',
            'template_data' => 'required|array',
            'category' => 'nullable|string|max:100',
            'is_public' => 'sometimes|boolean',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $template = Template::create([
            'organization_id' => $organization->id,
            'folder_id' => $data['folder_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'template_type' => $data['template_type'],
            'template_data' => $data['template_data'],
            'category' => $data['category'] ?? null,
            'is_public' => $data['is_public'] ?? false,
            'usage_count' => 0,
        ]);

        if (!empty($data['tag_ids'])) {
            $tagIds = Tag::whereIn('id', $data['tag_ids'])->where('organization_id', $organization->id)->pluck('id');
            $template->tags()->sync($tagIds);
        }

        return response()->json($template->load(['tags', 'folder', 'creator']), 201);
    }

    public function show(Request $request, $id)
    {
        $template = Template::with(['tags', 'folder', 'creator'])->findOrFail($id);
        $this->authorize('view', $template);
        return response()->json($template);
    }

    public function update(Request $request, $id)
    {
        $template = Template::findOrFail($id);
        $this->authorize('update', $template);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'folder_id' => 'sometimes|nullable|exists:folders,id',
            'thumbnail_url' => 'sometimes|nullable|url|max:2000',
            'template_type' => 'sometimes|in:slideshow,post,story,reel,custom,comment',
            'template_data' => 'sometimes|array',
            'category' => 'sometimes|nullable|string|max:100',
            'is_public' => 'sometimes|boolean',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $template->update($data);

        if (array_key_exists('tag_ids', $data)) {
            $organization = $template->organization;
            $tagIds = Tag::whereIn('id', $data['tag_ids'] ?? [])->where('organization_id', $organization->id)->pluck('id');
            $template->tags()->sync($tagIds);
        }

        return response()->json($template->load(['tags', 'folder']));
    }

    public function destroy(Request $request, $id)
    {
        $template = Template::findOrFail($id);
        $this->authorize('delete', $template);
        $template->delete();
        return response()->json(null, 204);
    }

    // POST /api/v1/templates/parse
    public function parse(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [Template::class, $organization]);

        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'raw_text' => 'required|string|min:50|max:200000',
            'platform' => 'sometimes|nullable|string|max:50',
            'folder_id' => 'sometimes|nullable|exists:folders,id',
        ]);

        $template = Template::create([
            'organization_id' => $organization->id,
            'folder_id' => $data['folder_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => $data['name'] ?? 'Parsed Template',
            'description' => 'Parsed from example text',
            'thumbnail_url' => null,
            'template_type' => 'post',
            'template_data' => ['structure' => ['sections' => []], 'constraints' => []],
            'category' => 'ai-parsed',
            'is_public' => false,
            'usage_count' => 0,
        ]);

        dispatch(new \App\Jobs\ParseTemplateFromTextJob($template->id, (string)$data['raw_text'], (string)($data['platform'] ?? 'generic')));

        return response()->json(['template_id' => $template->id]);
    }
}
