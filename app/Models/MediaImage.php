<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MediaImage extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id','pack_id','uploaded_by','filename','original_filename','file_path','thumbnail_path','file_size','mime_type','width','height','generation_type','ai_prompt'
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function pack() { return $this->belongsTo(MediaPack::class, 'pack_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
