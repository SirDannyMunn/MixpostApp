<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class KnowledgeChunk extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'knowledge_item_id',
        'organization_id',
        'user_id',
        'chunk_text',
        'chunk_type',
        'chunk_role',
        'chunk_kind',
        'is_active',
        'usage_policy',
        'authority',
        'confidence',
        'time_horizon',
        'domain',
        'actor',
        'source_type',
        'source_variant',
        'source_ref',
        'source_title',
        'tags',
        'token_count',
        'embedding',
        'embedding_model',
        'created_at',
        'source_text',
        'source_spans',
        'transformation_type',
    ];

    protected $casts = [
        'tags' => 'array',
        'source_ref' => 'array',
        'embedding' => 'array',
        'created_at' => 'datetime',
        'confidence' => 'float',
        'source_spans' => 'array',
        'is_active' => 'bool',
    ];

    public function knowledgeItem() { return $this->belongsTo(KnowledgeItem::class); }
}
