<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MediaPack extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['organization_id','name','description','created_by','image_count'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function images() { return $this->hasMany(MediaImage::class, 'pack_id'); }
}
