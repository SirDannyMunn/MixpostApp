<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SwipeItem extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id','user_id','platform','source_url','author_handle','raw_text','raw_text_sha256','engagement','saved_reason','created_at'
    ];

    protected $casts = [
        'engagement' => 'array',
        'created_at' => 'datetime',
    ];

    public function structures() { return $this->hasMany(SwipeStructure::class); }

    /**
     * Get the structural analysis for this swipe.
     */
    public function swipeStructures()
    {
        return $this->hasMany(SwipeStructure::class);
    }
}
