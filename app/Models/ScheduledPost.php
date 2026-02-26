<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ScheduledPost extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','project_id','created_by','caption','media_urls','scheduled_for','timezone','status','published_at','error_message'
    ];

    protected $casts = [
        'media_urls' => 'array',
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function accounts() { return $this->hasMany(ScheduledPostAccount::class); }
}
