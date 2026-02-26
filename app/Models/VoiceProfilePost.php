<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceProfilePost extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'locked' => 'boolean',
        'weight' => 'decimal:2',
    ];

    public function voiceProfile(): BelongsTo
    {
        return $this->belongsTo(VoiceProfile::class, 'voice_profile_id');
    }

    public function contentNode(): BelongsTo
    {
        return $this->belongsTo(\LaundryOS\SocialWatcher\Models\ContentNode::class, 'content_node_id');
    }

    /**
     * @deprecated Use contentNode() instead
     */
    public function normalizedContent(): BelongsTo
    {
        return $this->contentNode();
    }
}

