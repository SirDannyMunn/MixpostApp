<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GenerationSnapshot extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'organization_id','user_id','generated_post_id','conversation_id','conversation_message_id','platform','prompt','classification','intent','mode','template_id','template_data','voice_profile_id','voice_source','chunks','facts','swipes','user_context','options','output_content','created_at',
        // Structure resolution auditing
        'structure_resolution','structure_fit_score','resolved_structure_payload',
        // Creative intelligence
        'creative_intelligence',
        // Decision traceability
        'decision_trace','prompt_mutations','ci_rejections','ci_summary',
        // New high‑fidelity auditing fields
        'final_system_prompt','final_user_prompt','token_metrics','performance_metrics','repair_metrics',
        // Stage-centric tracking
        'llm_stages'
    ];

    protected $casts = [
        'classification' => 'array',
        'mode' => 'array',
        'template_data' => 'array',
        'chunks' => 'array',
        'facts' => 'array',
        'swipes' => 'array',
        'resolved_structure_payload' => 'array',
        'options' => 'array',
        'creative_intelligence' => 'array',
        'decision_trace' => 'array',
        'prompt_mutations' => 'array',
        'ci_rejections' => 'array',
        'token_metrics' => 'array',
        'performance_metrics' => 'array',
        'repair_metrics' => 'array',
        'llm_stages' => 'array',
        'created_at' => 'datetime',
    ];
}
