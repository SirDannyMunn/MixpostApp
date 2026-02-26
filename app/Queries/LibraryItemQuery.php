<?php

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LibraryItemQuery
{
    public static function bookmarksWithIngestion(string $organizationId): Builder
    {
        // Backwards-compatible method name.
        // The Library API should be ingestion-source-first, with an optional
        // bookmark join for bookmark-backed sources.

        return \App\Models\IngestionSource::query()
            ->where('ingestion_sources.organization_id', $organizationId)
            ->leftJoin('bookmarks as b', function ($join) use ($organizationId) {
                // Compare as text to avoid uuid cast errors for non-bookmark sources
                $join->on(DB::raw('b.id::text'), '=', DB::raw('ingestion_sources.source_id'))
                    ->where('ingestion_sources.source_type', '=', DB::raw("'bookmark'"))
                    ->where('b.organization_id', '=', $organizationId)
                    ->whereNull('b.deleted_at');
            })
            ->select([
                'ingestion_sources.*',
                'b.id as bookmark_id',
            ]);
    }
}
