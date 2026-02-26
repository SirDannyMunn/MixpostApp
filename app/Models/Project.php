<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Project extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','template_id','created_by','name','description','status','project_data','rendered_url','rendered_at'
    ];

    protected $casts = [
        'project_data' => 'array',
        'rendered_at' => 'datetime',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function template() { return $this->belongsTo(Template::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
