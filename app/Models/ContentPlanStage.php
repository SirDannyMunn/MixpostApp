<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPlanStage extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'content_plan_id',
        'day_index',
        'stage_type',
        'intent',
        'prompt_seed',
    ];

    protected $casts = [
        'day_index' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ContentPlanPost::class);
    }
}
