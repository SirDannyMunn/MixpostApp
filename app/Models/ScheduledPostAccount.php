<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ScheduledPostAccount extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'scheduled_post_id','social_account_id','platform_config','status','platform_post_id','published_at','error_message'
    ];

    protected $casts = [
        'platform_config' => 'array',
        'published_at' => 'datetime',
    ];

    public function scheduledPost() { return $this->belongsTo(ScheduledPost::class); }
    public function socialAccount() { return $this->belongsTo(SocialAccount::class); }
}
