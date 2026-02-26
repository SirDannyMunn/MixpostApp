<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Folder extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'parent_id',
        'display_name',
        'color',
        'icon',
        'position',
        'created_by',
        'metadata',
        'system_named_at',
        'display_renamed_at',
    ];

    protected $guarded = [
        'system_name',
    ];

    protected $appends = [
        'effective_name',
        // Back-compat: many clients still expect `name`.
        'name',
    ];

    protected $casts = [
        'metadata' => 'array',
        'system_named_at' => 'datetime',
        'display_renamed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getEffectiveNameAttribute(): string
    {
        $display = trim((string) ($this->display_name ?? ''));
        if ($display !== '') {
            return $display;
        }

        $system = trim((string) ($this->system_name ?? ''));
        if ($system !== '') {
            return $system;
        }

        // Legacy fallback (pre-migration column)
        $legacy = trim((string) ($this->attributes['name'] ?? ''));
        return $legacy !== '' ? $legacy : 'Folder';
    }

    /**
     * Back-compat name accessor.
     * - If `display_name` is set, it wins.
     * - Otherwise falls back to `system_name` (or legacy `name`).
     */
    public function getNameAttribute($value): string
    {
        $display = trim((string) ($this->display_name ?? ''));
        if ($display !== '') {
            return $display;
        }

        $system = trim((string) ($this->system_name ?? ''));
        if ($system !== '') {
            return $system;
        }

        return trim((string) ($value ?? ''));
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function parent() { return $this->belongsTo(Folder::class, 'parent_id'); }
    public function children() { return $this->hasMany(Folder::class, 'parent_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function bookmarks() { return $this->hasMany(Bookmark::class); }
    public function templates() { return $this->hasMany(Template::class); }
    public function embedding() { return $this->hasOne(FolderEmbedding::class, 'folder_id'); }

    public function ingestionSources(): BelongsToMany
    {
        return $this->belongsToMany(IngestionSource::class, 'ingestion_source_folders', 'folder_id', 'ingestion_source_id')
            ->withPivot(['created_by', 'created_at']);
    }
}
