<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Bookmark extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','folder_id','created_by','title','description','url','image_url','favicon_url','platform','platform_metadata','type','is_favorite','is_archived'
    ];

    protected $casts = [
        'platform_metadata' => 'array',
        'is_favorite' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function folder() { return $this->belongsTo(Folder::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function tags() { return $this->belongsToMany(Tag::class, 'bookmark_tags'); }
}
