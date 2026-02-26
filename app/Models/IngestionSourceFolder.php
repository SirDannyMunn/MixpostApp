<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngestionSourceFolder extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'ingestion_source_folders';

    protected $fillable = [
        'ingestion_source_id',
        'folder_id',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function ingestionSource()
    {
        return $this->belongsTo(IngestionSource::class);
    }
}
