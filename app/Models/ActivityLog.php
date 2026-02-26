<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ActivityLog extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = [
        'organization_id','user_id','action','subject_type','subject_id','description','properties','ip_address','user_agent','created_at'
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];
}
