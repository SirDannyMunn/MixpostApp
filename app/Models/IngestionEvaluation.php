<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngestionEvaluation extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'status',
        'format',
        'options',
        'scores',
        'issues',
        'recommendations',
        'report_paths',
        'ingestion_source_id',
        'knowledge_item_id',
    ];

    protected $casts = [
        'options' => 'array',
        'scores' => 'array',
        'issues' => 'array',
        'recommendations' => 'array',
        'report_paths' => 'array',
    ];
}

