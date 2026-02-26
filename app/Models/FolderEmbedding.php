<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FolderEmbedding extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $table = 'folder_embeddings';

    protected $fillable = [
        'folder_id',
        'org_id',
        'text_version',
        'representation_text',
        'stale_at',
        'updated_at',
    ];

    protected $casts = [
        'text_version' => 'integer',
        'stale_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }
}
