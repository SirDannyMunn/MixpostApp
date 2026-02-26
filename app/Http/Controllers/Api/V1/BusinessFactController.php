<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessFact;
use Illuminate\Http\Request;

class BusinessFactController extends Controller
{
    // GET /api/v1/business-facts
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $query = BusinessFact::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('confidence')
            ->orderByDesc('created_at');

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($search = $request->input('search')) {
            $query->where('text', 'like', "%$search%");
        }

        $perPage = (int) $request->input('per_page', 20);
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function ($f) {
            $score = (int) round(max(0, min(1, (float) ($f->confidence ?? 0))) * 100);
            return [
                'id' => (string) $f->id,
                'content' => (string) $f->text,
                'confidence_score' => $score,
            ];
        });

        return response()->json($page);
    }
}

