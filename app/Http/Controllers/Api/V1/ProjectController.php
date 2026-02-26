<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('viewAny', [Project::class, $organization]);

        $query = Project::with(['template:id,name', 'creator:id,name'])
            ->where('organization_id', $organization->id);
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('description', 'like', "%$search%");
            });
        }
        $query->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'));
        return response()->json($query->paginate((int)$request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [Project::class, $organization]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'template_id' => 'nullable|exists:templates,id',
            'project_data' => 'required|array',
        ]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'template_id' => $data['template_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'project_data' => $data['project_data'],
        ]);
        return response()->json($project, 201);
    }

    public function show(Request $request, $id)
    {
        $project = Project::with(['template', 'creator'])->findOrFail($id);
        $this->authorize('view', $project);
        return response()->json($this->transformProject($project));
    }

    public function update(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'template_id' => 'sometimes|nullable|exists:templates,id',
            'status' => 'sometimes|in:draft,in_progress,completed,archived',
            'project_data' => 'sometimes|array',
            'slides' => 'sometimes|array',
            'image_pack_id' => 'sometimes|nullable|string|max:100',
            'theme' => 'sometimes|nullable|string|max:50',
            'language' => 'sometimes|nullable|string|max:100',
            'rendered_url' => 'sometimes|nullable|url|max:2000',
            'rendered_at' => 'sometimes|nullable|date',
            'tag_ids' => 'sometimes|array',
        ]);

        // Update top-level fields (name may come as title)
        if (isset($data['title'])) {
            $project->name = $data['title'];
        }
        if (isset($data['name'])) {
            $project->name = $data['name'];
        }
        foreach (['description','template_id','status','rendered_url','rendered_at'] as $attr) {
            if (array_key_exists($attr, $data)) {
                $project->{$attr} = $data[$attr];
            }
        }

        // Merge project_data payload while preserving existing keys
        $pd = $project->project_data ?? [];
        if (isset($data['project_data']) && is_array($data['project_data'])) {
            $pd = array_replace_recursive($pd, $data['project_data']);
        }
        if (array_key_exists('slides', $data)) {
            $pd['slides'] = $data['slides'];
        }
        if (array_key_exists('image_pack_id', $data)) {
            $pd['image_pack_id'] = $data['image_pack_id'];
        }
        if (array_key_exists('theme', $data)) {
            $pd['theme'] = $data['theme'];
        }
        if (array_key_exists('language', $data)) {
            $pd['language'] = $data['language'];
        }
        if (array_key_exists('tag_ids', $data)) {
            $pd['tag_ids'] = $data['tag_ids'];
        }
        // Keep prompt if present in original
        $project->project_data = $pd;

        $project->save();

        return response()->json($this->transformProject($project));
    }

    public function destroy(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $this->authorize('delete', $project);
        $project->delete();
        return response()->json(null, 204);
    }
    
    protected function transformProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'type' => $project->project_data['type'] ?? 'project',
            'title' => $project->name,
            'status' => $project->status,
            'prompt' => $project->project_data['prompt'] ?? null,
            'slides' => $project->project_data['slides'] ?? [],
            'image_pack_id' => $project->project_data['image_pack_id'] ?? null,
            'theme' => $project->project_data['theme'] ?? null,
            'language' => $project->project_data['language'] ?? null,
            'folder_id' => $project->project_data['folder_id'] ?? null,
            'tags' => $project->project_data['tags'] ?? ($project->project_data['tag_ids'] ?? []),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }
}
