<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OrganizationMember extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id', 'user_id', 'role', 'invited_by', 'invited_at', 'joined_at'
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function inviter() { return $this->belongsTo(User::class, 'invited_by'); }
}
