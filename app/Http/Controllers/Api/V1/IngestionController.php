<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\IngestionSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestionController extends Controller
{
    /**
     * POST /api/v1/bookmarks/{bookmark}/ingest
     * Explicitly create an ingestion_sources record and queue processing.
     */
    public function ingestBookmark(Request $request, Bookmark $bookmark): JsonResponse
    {
        $this->authorize('view', $bookmark);
        $org = $request->attributes->get('organization');
        if ($org && $bookmark->organization_id !== $org->id) {
            abort(403);
        }

        $source = IngestionSource::firstOrCreate(
            [ 'source_type' => 'bookmark', 'source_id' => $bookmark->id ],
            [
                'organization_id' => $bookmark->organization_id,
                'user_id' => $request->user()->id,
                'origin' => 'browser',
                'platform' => $bookmark->platform,
                'raw_url' => $bookmark->url,
                'dedup_hash' => IngestionSource::dedupHashFromUrl($bookmark->url),
                'status' => 'pending',
            ]
        );

        dispatch(new \App\Jobs\ProcessIngestionSourceJob($source->id));

        return response()->json([ 'status' => 'queued' ], 202);
    }
}

