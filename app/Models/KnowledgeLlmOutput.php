<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeLlmOutput extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'knowledge_item_id',
        'model',
        'prompt_hash',
        'raw_output',
        'parsed_output',
        'created_at',
    ];

    protected $casts = [
        'raw_output' => 'array',
        'parsed_output' => 'array',
        'created_at' => 'datetime',
    ];

    public function knowledgeItem()
    {
        return $this->belongsTo(KnowledgeItem::class);
    }
}
