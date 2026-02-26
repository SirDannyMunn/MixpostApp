<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class IngestionSource extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'source_type',
        'source_id',
        'origin',
        'platform',
        'raw_url',
        'raw_text',
        'mime_type',
        'dedup_hash',
        'status',
        'dedup_reason',
        'error',
        'title',
        'metadata',
        'confidence_score',
        'quality_score',
        // Structure extraction linkage
        'swipe_structure_id',
        'structure_status',
        'structure_confidence',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'metadata' => 'array',
        'confidence_score' => 'float',
        'quality_score' => 'float',
        'quality' => 'array',
        'structure_confidence' => 'int',
    ];

    public static function normalizeUrl(?string $url): string
    {
        $u = trim((string) $url);
        if ($u === '') return '';
        $u = strtolower($u);
        // Strip trailing slash and fragments/query for coarse dedup
        $u = preg_replace('~[?#].*$~', '', $u) ?? $u;
        $u = rtrim($u, '/');
        return $u;
    }

    public static function dedupHashFromUrl(?string $url): string
    {
        return sha1(static::normalizeUrl($url));
    }

    public static function dedupHashFromText(?string $text): string
    {
        $t = trim((string) $text);
        if ($t === '') return sha1('empty');
        // Limit to first 2000 chars to avoid huge hashes
        $t = mb_substr($t, 0, 2000);
        return sha1('text:' . $t);
    }

    /**
     * Evaluation-isolated dedup hash: include the evaluation ID to avoid
     * cross-run dedup collisions while keeping the base text signal.
     */
    public static function dedupHashFromTextWithEval(?string $text, ?string $evaluationId): string
    {
        $base = static::dedupHashFromText($text);
        $eid = trim((string) $evaluationId);
        if ($eid === '') return $base;
        return sha1('eval:' . $eid . ':' . $base);
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function knowledgeItem() { return $this->belongsTo(KnowledgeItem::class); }
    
    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'ingestion_source_folders', 'ingestion_source_id', 'folder_id')
            ->withPivot(['created_by', 'created_at']);
    }
}
