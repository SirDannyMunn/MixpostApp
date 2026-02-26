<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiCanvasConversation extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'last_message_at',
        'message_count',
        'current_document_content',
        'current_version_id',
        // Active context linkage
        'last_snapshot_id',
        'active_voice_profile_id',
        'active_template_id',
        'active_swipe_ids',
        'active_fact_ids',
        'active_reference_ids',
        // Content planner state
        'planner_mode',
        'planner_state',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'active_swipe_ids' => 'array',
        'active_fact_ids' => 'array',
        'active_reference_ids' => 'array',
        'planner_state' => 'array',
    ];

    protected $appends = [
        'active_context',
    ];

    public function getActiveContextAttribute(): array
    {
        return [
            'voice_profile_id' => $this->active_voice_profile_id,
            'template_id' => $this->active_template_id,
            'swipe_ids' => (array) ($this->active_swipe_ids ?? []),
            'fact_ids' => (array) ($this->active_fact_ids ?? []),
            'reference_ids' => (array) ($this->active_reference_ids ?? []),
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(AiCanvasMessage::class, 'conversation_id');
    }

    public function versions()
    {
        return $this->hasMany(AiCanvasDocumentVersion::class, 'conversation_id');
    }
}
