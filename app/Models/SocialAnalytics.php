<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SocialAnalytics extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'social_account_id','date','followers_count','following_count','posts_count','likes_count','comments_count','shares_count','views_count','impressions_count','engagement_rate','raw_data'
    ];

    protected $casts = [
        'date' => 'date',
        'raw_data' => 'array',
        'engagement_rate' => 'decimal:2',
    ];

    public function account() { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
}
