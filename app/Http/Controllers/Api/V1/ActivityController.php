<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $query = ActivityLog::where('organization_id', $organization->id);
        if ($request->filled('user_id')) $query->where('user_id', $request->input('user_id'));
        if ($request->filled('action')) $query->where('action', $request->input('action'));
        if ($request->filled('subject_type')) $query->where('subject_type', $request->input('subject_type'));
        if ($request->filled('subject_id')) $query->where('subject_id', $request->input('subject_id'));
        if ($from = $request->input('from')) $query->where('created_at', '>=', $from);
        if ($to = $request->input('to')) $query->where('created_at', '<=', $to);
        $query->orderBy('created_at', 'desc');
        return response()->json($query->paginate((int)$request->input('per_page', 20)));
    }
}
