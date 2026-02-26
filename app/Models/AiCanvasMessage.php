<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiCanvasMessage extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'classification',
        'command',
        'created_version_id',
        'created_at',
        'metadata',
        'report',
        'planner',
    ];

    protected $casts = [
        'classification' => 'array',
        'command' => 'array',
        'metadata' => 'array',
        'report' => 'array',
        'planner' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiCanvasConversation::class, 'conversation_id');
    }

    public function createdVersion()
    {
        return $this->belongsTo(AiCanvasDocumentVersion::class, 'created_version_id');
    }
}

