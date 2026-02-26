<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Project;
use App\Models\Template;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['message' => 'Query q is required'], 422);
        }

        $limit = (int) $request->input('limit', 10);
        $bookmarks = Bookmark::where('organization_id', $organization->id)
            ->where(function($qq) use ($q){
                $qq->where('title', 'like', "%$q%")
                   ->orWhere('description', 'like', "%$q%")
                   ->orWhere('url', 'like', "%$q%");
            })
            ->limit($limit)->get(['id','title','url','description','created_at']);

        $templates = Template::where('organization_id', $organization->id)
            ->where(function($qq) use ($q){
                $qq->where('name', 'like', "%$q%")
                   ->orWhere('description', 'like', "%$q%");
            })
            ->limit($limit)->get(['id','name','description','created_at']);

        $projects = Project::where('organization_id', $organization->id)
            ->where(function($qq) use ($q){
                $qq->where('name', 'like', "%$q%")
                   ->orWhere('description', 'like', "%$q%");
            })
            ->limit($limit)->get(['id','name','description','status','created_at']);

        return response()->json([
            'bookmarks' => $bookmarks,
            'templates' => $templates,
            'projects' => $projects,
        ]);
    }
}
