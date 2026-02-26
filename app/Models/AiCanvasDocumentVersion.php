<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Models\Media;

class AiCanvasDocumentVersion extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'version_number',
        'title',
        'content',
        'content_preview',
        'word_count',
        'command_type',
        'command_target',
        'media_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiCanvasConversation::class, 'conversation_id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}

