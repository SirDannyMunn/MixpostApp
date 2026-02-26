<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiCanvasConversation;
use App\Models\AiCanvasMessage;
use Illuminate\Http\Request;

class AiCanvasConversationController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $query = AiCanvasConversation::where('organization_id', $organization->id);

        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');
        if (!in_array($sortBy, ['created_at', 'updated_at', 'title'])) {
            $sortBy = 'updated_at';
        }
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int)($request->input('per_page', 20));
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $user = $request->user();

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $conversation = AiCanvasConversation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'title' => $data['title'] ?? null,
            'current_document_content' => "# Welcome to AI Canvas\n\nStart chatting to create and edit your document.",
            'message_count' => 0,
        ]);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, string $id)
    {
        $conversation = AiCanvasConversation::findOrFail($id);

        $include = array_filter(explode(',', (string)$request->query('include')));
        $result = $conversation->toArray();
        if (in_array('messages', $include)) {
            $result['messages'] = AiCanvasMessage::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'asc')
                ->get();
        }
        if (in_array('versions', $include)) {
            $result['versions'] = $conversation->versions()->orderBy('version_number', 'desc')->get();
        }
        return response()->json($result);
    }

    public function update(Request $request, string $id)
    {
        $conversation = AiCanvasConversation::findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|nullable|string|max:255',
            'current_document_content' => 'sometimes|nullable|string',
        ]);

        foreach (['title', 'current_document_content'] as $field) {
            if (array_key_exists($field, $data)) {
                $conversation->{$field} = $data[$field];
            }
        }
        $conversation->save();

        return response()->json($conversation);
    }

    public function destroy(Request $request, string $id)
    {
        $conversation = AiCanvasConversation::findOrFail($id);
        $conversation->delete();
        return response()->json(null, 204);
    }

    public function document(Request $request, string $id)
    {
        $conversation = AiCanvasConversation::findOrFail($id);
        $content = (string) ($conversation->current_document_content ?? '');
        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }
}
