<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiCanvasConversation;
use App\Models\AiCanvasMessage;
use Illuminate\Http\Request;

class AiCanvasMessageController extends Controller
{
    public function index(Request $request, string $conversationId)
    {
        $conversation = AiCanvasConversation::findOrFail($conversationId);

        $include = array_filter(explode(',', (string)$request->query('include')));
        $query = AiCanvasMessage::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'asc');

        // Eager-load created version when requested
        if (in_array('version', $include) || in_array('created_version', $include)) {
            $query = $query->with(['createdVersion']);
        }

        $perPage = (int)($request->input('per_page', 50));
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, string $conversationId)
    {
        $conversation = AiCanvasConversation::findOrFail($conversationId);

        $data = $request->validate([
            'role' => 'required|in:user,assistant,system',
            'content' => 'required|string|max:50000',
            'classification' => 'sometimes|array',
            'command' => 'sometimes|nullable|array',
            'metadata' => 'sometimes|nullable|array',
            'report' => 'sometimes|nullable|array',
            'planner' => 'sometimes|nullable|array',
        ]);

        $message = AiCanvasMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $data['role'],
            'content' => $data['content'],
            'classification' => $data['classification'] ?? null,
            'command' => $data['command'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'report' => $data['report'] ?? null,
            'planner' => $data['planner'] ?? null,
            'created_at' => now(),
        ]);

        $conversation->message_count = (int)$conversation->message_count + 1;
        $conversation->last_message_at = now();
        $conversation->save();

        return response()->json($message, 201);
    }
}
