<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BusinessFact extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id','user_id','type','text','confidence','source_knowledge_item_id','created_at'
    ];

    protected $casts = [
        'confidence' => 'float',
        'created_at' => 'datetime',
    ];

    public function knowledgeItem()
    {
        return $this->belongsTo(KnowledgeItem::class, 'source_knowledge_item_id');
    }
}
