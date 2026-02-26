<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GenerationQualityReport extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'organization_id','user_id','generated_post_id','snapshot_id','intent','overall_score','scores','created_at'
    ];

    protected $casts = [
        'scores' => 'array',
        'created_at' => 'datetime',
    ];
}

