<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiCanvasConversation;
use App\Models\AiCanvasDocumentVersion;
use App\Models\AiCanvasMessage;
use Illuminate\Http\Request;
use Inovector\Mixpost\Http\Resources\MediaResource;

class AiCanvasVersionController extends Controller
{
    public function store(Request $request, string $conversationId)
    {
        $conversation = AiCanvasConversation::findOrFail($conversationId);

        $data = $request->validate([
            'message_id' => 'sometimes|nullable|string',
            'content' => 'required|string',
            'command_type' => 'required|in:replace_document,replace_section,insert_content',
            'command_target' => 'sometimes|nullable|string',
            'media_id' => 'sometimes|nullable|string',
            'command_target' => 'sometimes|nullable|string|max:255',
        ]);

        // Allow sentinel for manual edits; treat as null
        if (($data['message_id'] ?? null) === 'manual_edit') {
            $data['message_id'] = null;
        }

        // If message_id is provided (not null), ensure it exists and is a valid UUID
        if (!empty($data['message_id'])) {
            // Basic UUID v4-ish check (accepts any UUID format)
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', (string)$data['message_id'])) {
                return response()->json([
                    'message' => 'Invalid message_id format',
                    'errors' => ['message_id' => ['message_id must be a UUID or null']],
                ], 422);
            }
            if (!AiCanvasMessage::where('id', $data['message_id'])->exists()) {
                return response()->json([
                    'message' => 'message_id does not exist',
                    'errors' => ['message_id' => ['message_id must reference an existing message']],
                ], 422);
            }
        }

        $nextVersion = (int) (AiCanvasDocumentVersion::where('conversation_id', $conversation->id)->max('version_number') ?? 0) + 1;

        $title = self::extractTitleFromMarkdown($data['content']);
        $preview = mb_substr(trim(strip_tags($data['content'])), 0, 200);
        $wordCount = self::countWords($data['content']);

        $version = AiCanvasDocumentVersion::create([
            'conversation_id' => $conversation->id,
            'message_id' => $data['message_id'],
            'version_number' => $nextVersion,
            'title' => $title,
            'content' => $data['content'],
            'content_preview' => $preview,
            'word_count' => $wordCount,
            'command_type' => $data['command_type'],
            'command_target' => $data['command_target'] ?? null,
            'media_id' => $data['media_id'] ?? null,
            'created_at' => now(),
        ]);

        // Link message -> version
        if (!empty($data['message_id'])) {
            AiCanvasMessage::where('id', $data['message_id'])->update(['created_version_id' => $version->id]);
        }

        // Update conversation current state
        $conversation->current_document_content = $version->content;
        $conversation->current_version_id = $version->id;
        $conversation->save();

        return response()->json($version, 201);
    }

    public function show(Request $request, string $versionId)
    {
        $version = AiCanvasDocumentVersion::with('media')->findOrFail($versionId);
        $data = $version->toArray();
        if ($version->media) {
            $data['media'] = (new MediaResource($version->media))->resolve();
        }
        return response()->json($data);
    }

    public function indexForConversation(Request $request, string $conversationId)
    {
        $conversation = AiCanvasConversation::findOrFail($conversationId);
        $sortOrder = $request->input('sort_order', 'desc');
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }
        $query = $conversation->versions()->orderBy('version_number', $sortOrder);
        $perPage = (int)($request->input('per_page', 20));
        return response()->json($query->paginate($perPage));
    }

    public function restore(Request $request, string $versionId)
    {
        $version = AiCanvasDocumentVersion::findOrFail($versionId);
        $conversation = $version->conversation;

        $nextVersion = (int) (AiCanvasDocumentVersion::where('conversation_id', $conversation->id)->max('version_number') ?? 0) + 1;

        $new = AiCanvasDocumentVersion::create([
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'version_number' => $nextVersion,
            'title' => $version->title,
            'content' => $version->content,
            'content_preview' => $version->content_preview,
            'word_count' => $version->word_count,
            'command_type' => 'replace_document',
            'command_target' => null,
            'media_id' => $version->media_id,
            'created_at' => now(),
        ]);

        $conversation->current_document_content = $new->content;
        $conversation->current_version_id = $new->id;
        $conversation->save();

        $new->load('media');
        $versionData = $new->toArray();
        if ($new->media) {
            $versionData['media'] = (new MediaResource($new->media))->resolve();
        }

        return response()->json([
            'message' => 'Document restored to selected version',
            'new_version' => $versionData,
        ]);
    }

    public function updateMedia(Request $request, string $versionId)
    {
        $version = AiCanvasDocumentVersion::findOrFail($versionId);

        $data = $request->validate([
            'media_id' => 'nullable|string',
        ]);

        $version->update(['media_id' => $data['media_id']]);

        $version->load('media');

        $data = $version->toArray();
        if ($version->media) {
            $data['media'] = (new MediaResource($version->media))->resolve();
        }
        return response()->json($data);
    }

    public function document(Request $request, string $versionId)
    {
        $version = AiCanvasDocumentVersion::findOrFail($versionId);
        $content = (string) ($version->content ?? '');
        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    protected static function extractTitleFromMarkdown(string $md): ?string
    {
        foreach (preg_split("/\r?\n/", $md) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_starts_with($line, '#')) {
                return ltrim($line, "# ");
            }
        }
        return null;
    }

    protected static function countWords(string $text): int
    {
        $plain = strip_tags($text);
        $plain = preg_replace('/[#*_`>\-]+/', ' ', $plain);
        $plain = preg_replace('/\s+/', ' ', (string)$plain);
        $plain = trim((string)$plain);
        if ($plain === '') return 0;
        return str_word_count($plain);
    }
}
