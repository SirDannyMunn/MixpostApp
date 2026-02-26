<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SentenceRewrite extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id','user_id','generated_post_id','original_sentence','instruction','rewritten_sentence','meta','created_at'
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
