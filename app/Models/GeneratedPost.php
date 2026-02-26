<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GeneratedPost extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','user_id','platform','intent','funnel_stage','topic','template_id','request','context_snapshot','content','status','validation'
    ];

    protected $casts = [
        'request' => 'array',
        'context_snapshot' => 'array',
        'validation' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function template() { return $this->belongsTo(Template::class); }
}
