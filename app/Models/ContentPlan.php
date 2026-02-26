<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPlan extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'name',
        'plan_type',
        'duration_days',
        'platform',
        'goal',
        'audience',
        'voice_profile_id',
        'status',
        'continuity_state',
    ];

    protected $casts = [
        'continuity_state' => 'array',
        'duration_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCanvasConversation::class, 'conversation_id');
    }

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(VoiceProfile::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ContentPlanStage::class);
    }

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(
            ContentPlanPost::class,
            ContentPlanStage::class,
            'content_plan_id', // Foreign key on stages table
            'content_plan_stage_id', // Foreign key on posts table
            'id', // Local key on plans table
            'id' // Local key on stages table
        );
    }
}
