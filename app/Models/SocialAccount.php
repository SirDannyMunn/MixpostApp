<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SocialAccount extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','connected_by','platform','platform_user_id','username','display_name','avatar_url','access_token','refresh_token','token_expires_at','is_active','last_sync_at','scopes','connected_at'
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'connected_at' => 'datetime',
        'scopes' => 'array',
        'is_active' => 'boolean',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function connector() { return $this->belongsTo(User::class, 'connected_by'); }
}
