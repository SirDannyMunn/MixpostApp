<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaImage;
use App\Models\MediaPack;
use App\Services\AIImageGenerationService;
use App\Services\MediaImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaImageController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('viewAny', [MediaImage::class, $organization]);

        $query = MediaImage::where('organization_id', $organization->id)->with(['pack:id,name']);
        if ($request->filled('pack_id')) {
            $query->where('pack_id', $request->input('pack_id'));
        }
        if ($gen = $request->input('generation_type')) {
            $query->where('generation_type', $gen);
        }
        if ($search = $request->input('search')) {
            $query->where('original_filename', 'like', "%$search%");
        }
        $query->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'));
        return response()->json($query->paginate((int)$request->input('per_page', 20)));
    }

    public function upload(Request $request, MediaImageService $mediaService)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [MediaImage::class, $organization]);

        $data = $request->validate([
            'image'   => 'required|image|max:10240',
            'pack_id' => 'sometimes|nullable|exists:media_packs,id',
        ]);

        $file = $request->file('image');

        $image = $mediaService->uploadImage(
            $organization,
            $file,
            $request->user()->id,
            $data['pack_id'] ?? null
        );

        if (!empty($data['pack_id'])) {
            MediaPack::where('id', $data['pack_id'])->increment('image_count');
        }

        return response()->json($image, 201);
    }

    public function generate(Request $request, AIImageGenerationService $aiService)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [MediaImage::class, $organization]);

        $data = $request->validate([
            'prompt'       => 'required|string|max:1000',
            'aspect_ratio' => 'sometimes|in:1:1,16:9,9:16,4:3',
            'filename'     => 'sometimes|nullable|string|max:255',
            'pack_id'      => 'sometimes|nullable|exists:media_packs,id',
            'model'        => 'sometimes|nullable|string',
        ]);

        try {
            $image = $aiService->generateAndSaveImage(
                $organization,
                $request->user()->id,
                $data['prompt'],
                $data['aspect_ratio'] ?? '1:1',
                $data['filename'] ?? null,
                $data['pack_id'] ?? null,
                $data['model'] ?? null
            );

            if (!empty($data['pack_id'])) {
                MediaPack::where('id', $data['pack_id'])->increment('image_count');
            }

            return response()->json($image, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate image',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $image = MediaImage::findOrFail($id);
        $this->authorize('update', $image);

        $data = $request->validate([
            'pack_id' => 'sometimes|nullable|exists:media_packs,id',
        ]);
        $oldPack = $image->pack_id;
        $image->update($data);
        if (array_key_exists('pack_id', $data) && $data['pack_id'] !== $oldPack) {
            if ($oldPack) MediaPack::where('id', $oldPack)->decrement('image_count');
            if (!empty($data['pack_id'])) MediaPack::where('id', $data['pack_id'])->increment('image_count');
        }
        return response()->json($image);
    }

    public function destroy(Request $request, $id, MediaImageService $mediaService)
    {
        $image = MediaImage::findOrFail($id);
        $this->authorize('delete', $image);
        $packId = $image->pack_id;
        $mediaService->deleteImage($image);
        if ($packId) MediaPack::where('id', $packId)->decrement('image_count');
        return response()->json(null, 204);
    }
}
