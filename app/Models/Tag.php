<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tag extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['organization_id','name','color','created_by'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function bookmarks() { return $this->belongsToMany(Bookmark::class, 'bookmark_tags'); }
    public function templates() { return $this->belongsToMany(Template::class, 'template_tags'); }
}
