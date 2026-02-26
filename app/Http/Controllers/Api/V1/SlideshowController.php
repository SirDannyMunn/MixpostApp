<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateSlideshowRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Services\OpenAIService;
use Illuminate\Http\Request;

class SlideshowController extends Controller
{
    public function generate(GenerateSlideshowRequest $request)
    {
        $org = $this->resolveOrganization($request);
        if (! $org) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $aiService = app(OpenAIService::class);
        $payload = [
            'prompt' => $request->input('prompt'),
            'slide_count' => (int)$request->input('slide_count'),
            'language' => $request->input('language'),
            'theme' => $request->input('theme', 'modern'),
        ];
        $generated = $aiService->generateSlideshowContent($payload);

        $slides = [];
        $theme = $request->input('theme', 'modern');
        $imagePack = $request->input('image_pack_id');
        foreach ($generated['slides'] as $index => $slideContent) {
            $slides[] = [
                'id' => 'slide_' . ($index + 1),
                'backgroundImage' => $this->getImageFromPack($imagePack, $index),
                'backgroundColor' => $this->getThemeColor($theme),
                'textElements' => $this->normalizeTextElements($slideContent),
                'imageOverlays' => [],
                'gridLayout' => 'none',
                'gridCells' => [],
            ];
        }

        $createdBy = $this->guessCreatorId($org);

        $project = Project::create([
            'organization_id' => $org->id,
            'template_id' => null,
            'created_by' => $createdBy,
            'name' => $generated['title'] ?? $this->extractTitle($request->input('prompt')),
            'description' => null,
            'status' => 'draft',
            'project_data' => [
                'prompt' => $request->input('prompt'),
                'slides' => $slides,
                'image_pack_id' => $imagePack,
                'theme' => $theme,
                'language' => $request->input('language'),
                'type' => 'slideshow',
            ],
        ]);

        return response()->json($this->transformProject($project), 201);
    }

    public function copy(Request $request, int $id)
    {
        $org = $this->resolveOrganization($request);
        if (! $org) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $project = Project::where('organization_id', $org->id)->findOrFail($id);
        $copy = $project->replicate();
        $copy->name = $project->name . ' (Copy)';
        $copy->status = 'draft';
        $copy->created_at = now();
        $copy->updated_at = now();
        $copy->save();

        return response()->json($this->transformProject($copy), 201);
    }

    protected function resolveOrganization(Request $request): ?Organization
    {
        $wanted = $request->header('X-Organization-Id') ?: $request->query('organization_id');
        if (! $wanted) return null;
        return is_numeric($wanted)
            ? Organization::find($wanted)
            : Organization::where('slug', $wanted)->first();
    }

    protected function getThemeColor(?string $theme): string
    {
        return match ($theme) {
            'minimal' => '#111827',
            'vibrant' => '#1f2937',
            'dark' => '#0b0f14',
            'sunset' => '#2b1d1f',
            'ocean' => '#0a2233',
            default => '#1a1a1f',
        };
    }

    protected function getImageFromPack(?string $packId, int $index): ?string
    {
        if (! $packId) return null;
        // Simple deterministic placeholder; real impl would fetch from MediaPack
        return url('/images/packs/' . $packId . '/bg_' . (($index % 5) + 1) . '.jpg');
    }

    protected function extractTitle(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') return 'Untitled Slideshow';
        $words = preg_split('/\s+/', $prompt);
        $title = implode(' ', array_slice($words, 0, 6));
        return ucfirst(rtrim($title, '.:;!?, '));
    }

    protected function normalizeTextElements(array $slideContent): array
    {
        $elements = [];
        if (isset($slideContent['text_elements']) && is_array($slideContent['text_elements'])) {
            foreach ($slideContent['text_elements'] as $idx => $el) {
                $elements[] = [
                    'id' => 'text_' . ($idx + 1),
                    'text' => (string)($el['text'] ?? ''),
                    'x' => (int)($el['x'] ?? 100),
                    'y' => (int)($el['y'] ?? (200 + $idx * 80)),
                    'fontSize' => (int)($el['font_size'] ?? 48),
                    'fontFamily' => (string)($el['font_family'] ?? 'Inter'),
                    'fontWeight' => (string)($el['font_weight'] ?? '600'),
                    'color' => (string)($el['color'] ?? '#ffffff'),
                    'textAlign' => (string)($el['text_align'] ?? 'center'),
                ];
            }
        } else {
            // Map title/subtitle if provided
            if (!empty($slideContent['title'])) {
                $elements[] = [
                    'id' => 'text_1',
                    'text' => (string)$slideContent['title'],
                    'x' => 100,
                    'y' => 200,
                    'fontSize' => 64,
                    'fontFamily' => 'Inter',
                    'fontWeight' => '700',
                    'color' => '#ffffff',
                    'textAlign' => 'center',
                ];
            }
            if (!empty($slideContent['subtitle'])) {
                $elements[] = [
                    'id' => 'text_2',
                    'text' => (string)$slideContent['subtitle'],
                    'x' => 100,
                    'y' => 300,
                    'fontSize' => 24,
                    'fontFamily' => 'Inter',
                    'fontWeight' => '400',
                    'color' => '#d9ff00',
                    'textAlign' => 'center',
                ];
            }
        }
        return $elements;
    }

    protected function transformProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'type' => 'slideshow',
            'title' => $project->name,
            'status' => $project->status,
            'prompt' => $project->project_data['prompt'] ?? null,
            'slides' => $project->project_data['slides'] ?? [],
            'image_pack_id' => $project->project_data['image_pack_id'] ?? null,
            'theme' => $project->project_data['theme'] ?? null,
            'language' => $project->project_data['language'] ?? null,
            'folder_id' => $project->project_data['folder_id'] ?? null,
            'tags' => $project->project_data['tags'] ?? [],
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }

    protected function guessCreatorId(Organization $org): int
    {
        // Best-effort: pick first member as creator; fallback to first user
        $member = $org->members()->first();
        if ($member) {
            return $member->id;
        }
        $firstUser = \App\Models\User::query()->first();
        if ($firstUser) {
            return $firstUser->id;
        }
        // As a last resort, create a system user to satisfy non-null constraint
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'system@local'],
            ['name' => 'System', 'password' => 'password']
        );
        return $user->id;
    }
}

