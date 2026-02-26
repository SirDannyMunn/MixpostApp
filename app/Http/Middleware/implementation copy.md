# Media Library Complete CRUD API Implementation

## Overview
This document provides the complete Laravel backend implementation for ALL Media Library CRUD operations, including Media Packs and Media Images. This supplements document `07-media-storage-ai-implementation.md` with the full REST API endpoints.

---

## Table of Contents
1. [Media Packs CRUD](#media-packs-crud)
2. [Media Images CRUD](#media-images-crud)
3. [Controllers](#controllers)
4. [Routes](#routes)
5. [Validation](#validation)
6. [Testing](#testing)

---

## Media Packs CRUD

### Database Migration

**File:** `database/migrations/2024_01_XX_create_media_packs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('image_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_packs');
    }
};
```

### Model

**File:** `app/Models/MediaPack.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaPack extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'image_count',
    ];

    protected $casts = [
        'image_count' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(MediaImage::class, 'pack_id');
    }

    /**
     * Update image count for this pack
     */
    public function updateImageCount(): void
    {
        $this->update([
            'image_count' => $this->images()->count()
        ]);
    }
}
```

Add to `Organization` model:

```php
public function mediaPacks(): HasMany
{
    return $this->hasMany(MediaPack::class);
}
```

### Controller

**File:** `app/Http/Controllers/Api/MediaPackController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaPack;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MediaPackController extends Controller
{
    /**
     * List all media packs for organization
     * 
     * GET /api/v1/media/packs
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $request->user()->currentOrganization;

        $packs = $organization->mediaPacks()
            ->withCount('images')
            ->orderBy('created_at', 'desc')
            ->get();

        // Update image_count from relationship count
        $packs->each(function ($pack) {
            $pack->image_count = $pack->images_count;
        });

        return response()->json([
            'data' => $packs
        ]);
    }

    /**
     * Create a new media pack
     * 
     * POST /api/v1/media/packs
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $organization = $request->user()->currentOrganization;

        $pack = MediaPack::create([
            'organization_id' => $organization->id,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'image_count' => 0,
        ]);

        return response()->json($pack, 201);
    }

    /**
     * Get a single media pack
     * 
     * GET /api/v1/media/packs/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $organization = $request->user()->currentOrganization;
        
        $pack = $organization->mediaPacks()
            ->withCount('images')
            ->findOrFail($id);

        $pack->image_count = $pack->images_count;

        return response()->json($pack);
    }

    /**
     * Update a media pack
     * 
     * PATCH /api/v1/media/packs/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $organization = $request->user()->currentOrganization;
        $pack = $organization->mediaPacks()->findOrFail($id);

        $pack->update($request->only(['name', 'description']));

        return response()->json($pack);
    }

    /**
     * Delete a media pack
     * Note: Images in the pack will have pack_id set to null (not deleted)
     * 
     * DELETE /api/v1/media/packs/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $organization = $request->user()->currentOrganization;
        $pack = $organization->mediaPacks()->findOrFail($id);

        // Set all images' pack_id to null before deleting pack
        $pack->images()->update(['pack_id' => null]);

        $pack->delete();

        return response()->json([
            'message' => 'Media pack deleted successfully'
        ]);
    }
}
```

---

## Media Images CRUD

### Complete Controller with All CRUD Operations

**File:** `app/Http/Controllers/Api/MediaImageController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MediaImageService;
use App\Services\AIImageGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MediaImageController extends Controller
{
    protected MediaImageService $mediaService;

    public function __construct(MediaImageService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * List images (with optional pack filter)
     * 
     * GET /api/v1/media/images
     * GET /api/v1/media/images?pack_id=123
     * GET /api/v1/media/images?pack_id=null (uncategorized)
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $request->user()->currentOrganization;

        $query = $organization->mediaImages()
            ->with('pack')
            ->orderBy('created_at', 'desc');

        // Filter by pack
        if ($request->has('pack_id')) {
            if ($request->pack_id === 'null' || $request->pack_id === '') {
                $query->whereNull('pack_id');
            } else {
                $query->where('pack_id', $request->pack_id);
            }
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $images = $query->paginate($perPage);

        return response()->json($images);
    }

    /**
     * Get a single image
     * 
     * GET /api/v1/media/images/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $organization = $request->user()->currentOrganization;
        
        $image = $organization->mediaImages()
            ->with('pack')
            ->findOrFail($id);

        return response()->json($image);
    }

    /**
     * Upload image
     * 
     * POST /api/v1/media/images/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:10240', // Max 10MB
            'filename' => 'nullable|string|max:255',
            'pack_id' => 'nullable|exists:media_packs,id',
        ]);

        try {
            $organization = $request->user()->currentOrganization;

            $image = $this->mediaService->uploadImage(
                $organization,
                $request->file('file'),
                $request->input('filename'),
                $request->input('pack_id')
            );

            // Update pack image count
            if ($image->pack_id) {
                $image->pack->updateImageCount();
            }

            return response()->json($image, 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload image',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Generate image using AI
     * 
     * POST /api/v1/media/images/generate
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:1000',
            'aspect_ratio' => 'required|in:1:1,16:9,9:16,4:3',
            'filename' => 'nullable|string|max:255',
            'pack_id' => 'nullable|exists:media_packs,id',
            'model' => 'nullable|string',
        ]);

        try {
            $organization = $request->user()->currentOrganization;

            $aiService = app(AIImageGenerationService::class);

            $image = $aiService->generateAndSaveImage(
                $organization,
                $request->input('prompt'),
                $request->input('aspect_ratio', '1:1'),
                $request->input('filename'),
                $request->input('pack_id'),
                $request->input('model')
            );

            // Update pack image count
            if ($image->pack_id) {
                $image->pack->updateImageCount();
            }

            return response()->json($image, 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate image',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Update image (rename or move to different pack)
     * 
     * PATCH /api/v1/media/images/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'filename' => 'sometimes|required|string|max:255',
            'pack_id' => 'nullable|exists:media_packs,id',
        ]);

        $organization = $request->user()->currentOrganization;
        $image = $organization->mediaImages()->findOrFail($id);

        $oldPackId = $image->pack_id;

        $image->update($request->only(['filename', 'pack_id']));

        // Update image counts for affected packs
        if ($oldPackId && $oldPackId !== $request->pack_id) {
            // Update old pack count
            if ($oldPack = MediaPack::find($oldPackId)) {
                $oldPack->updateImageCount();
            }
        }

        if ($image->pack_id) {
            // Update new pack count
            $image->pack->updateImageCount();
        }

        return response()->json($image->fresh('pack'));
    }

    /**
     * Delete image
     * 
     * DELETE /api/v1/media/images/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $organization = $request->user()->currentOrganization;
        $image = $organization->mediaImages()->findOrFail($id);

        $packId = $image->pack_id;

        // Delete the image (this will also delete from S3)
        $this->mediaService->deleteImage($image);

        // Update pack image count
        if ($packId) {
            if ($pack = MediaPack::find($packId)) {
                $pack->updateImageCount();
            }
        }

        return response()->json(['message' => 'Image deleted successfully']);
    }
}
```

---

## Routes

**File:** `routes/api.php`

```php
<?php

use App\Http\Controllers\Api\MediaPackController;
use App\Http\Controllers\Api\MediaImageController;

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    
    // ==================== Media Packs ====================
    Route::prefix('media/packs')->group(function () {
        Route::get('/', [MediaPackController::class, 'index']);
        Route::post('/', [MediaPackController::class, 'store']);
        Route::get('/{id}', [MediaPackController::class, 'show']);
        Route::patch('/{id}', [MediaPackController::class, 'update']);
        Route::delete('/{id}', [MediaPackController::class, 'destroy']);
    });

    // ==================== Media Images ====================
    Route::prefix('media/images')->group(function () {
        Route::get('/', [MediaImageController::class, 'index']);
        Route::get('/{id}', [MediaImageController::class, 'show']);
        Route::post('/upload', [MediaImageController::class, 'upload']);
        Route::post('/generate', [MediaImageController::class, 'generate'])
            ->middleware('throttle:10,1'); // Rate limit: 10 generations per minute
        Route::patch('/{id}', [MediaImageController::class, 'update']);
        Route::delete('/{id}', [MediaImageController::class, 'destroy']);
    });
});
```

---

## Request/Response Examples

### Media Packs

#### List Packs
```bash
GET /api/v1/media/packs
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK
{
  "data": [
    {
      "id": 1,
      "organization_id": 1,
      "name": "Brand Assets",
      "description": "Company logos and branding materials",
      "image_count": 15,
      "created_at": "2024-12-01T10:00:00Z",
      "updated_at": "2024-12-14T15:30:00Z"
    }
  ]
}
```

#### Create Pack
```bash
POST /api/v1/media/packs
Authorization: Bearer {token}
X-Organization-ID: 1
Content-Type: application/json

{
  "name": "Product Photos",
  "description": "High-quality product photography"
}

Response: 201 Created
{
  "id": 2,
  "organization_id": 1,
  "name": "Product Photos",
  "description": "High-quality product photography",
  "image_count": 0,
  "created_at": "2024-12-14T16:00:00Z",
  "updated_at": "2024-12-14T16:00:00Z"
}
```

#### Update Pack
```bash
PATCH /api/v1/media/packs/2
Authorization: Bearer {token}
X-Organization-ID: 1
Content-Type: application/json

{
  "name": "Product Images",
  "description": "Updated description"
}

Response: 200 OK
{
  "id": 2,
  "organization_id": 1,
  "name": "Product Images",
  "description": "Updated description",
  "image_count": 0,
  "created_at": "2024-12-14T16:00:00Z",
  "updated_at": "2024-12-14T16:05:00Z"
}
```

#### Delete Pack
```bash
DELETE /api/v1/media/packs/2
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK
{
  "message": "Media pack deleted successfully"
}
```

### Media Images

#### List Images (All)
```bash
GET /api/v1/media/images
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "organization_id": 1,
      "pack_id": 1,
      "filename": "logo-primary.png",
      "url": "https://s3.amazonaws.com/.../logo-primary.png",
      "thumbnail_url": "https://s3.amazonaws.com/.../thumbnails/logo-primary.png",
      "size": 245680,
      "width": 1920,
      "height": 1080,
      "mime_type": "image/png",
      "metadata": null,
      "created_at": "2024-12-01T10:00:00Z",
      "updated_at": "2024-12-01T10:00:00Z",
      "pack": {
        "id": 1,
        "name": "Brand Assets",
        "description": "Company logos"
      }
    }
  ],
  "per_page": 50,
  "total": 1
}
```

#### List Images (By Pack)
```bash
GET /api/v1/media/images?pack_id=1
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK (filtered by pack_id=1)
```

#### List Images (Uncategorized)
```bash
GET /api/v1/media/images?pack_id=null
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK (images where pack_id is null)
```

#### Update Image
```bash
PATCH /api/v1/media/images/1
Authorization: Bearer {token}
X-Organization-ID: 1
Content-Type: application/json

{
  "filename": "logo-primary-updated.png",
  "pack_id": 2
}

Response: 200 OK
{
  "id": 1,
  "organization_id": 1,
  "pack_id": 2,
  "filename": "logo-primary-updated.png",
  "url": "https://s3.amazonaws.com/.../logo-primary.png",
  "thumbnail_url": "https://s3.amazonaws.com/.../thumbnails/logo-primary.png",
  "size": 245680,
  "width": 1920,
  "height": 1080,
  "mime_type": "image/png",
  "created_at": "2024-12-01T10:00:00Z",
  "updated_at": "2024-12-14T16:10:00Z",
  "pack": {
    "id": 2,
    "name": "Product Images"
  }
}
```

#### Delete Image
```bash
DELETE /api/v1/media/images/1
Authorization: Bearer {token}
X-Organization-ID: 1

Response: 200 OK
{
  "message": "Image deleted successfully"
}
```

---

## Validation Rules Summary

### Media Packs
- **name**: required, string, max:255
- **description**: nullable, string, max:1000

### Media Images (Upload)
- **file**: required, file, image, max:10240 (10MB)
- **filename**: nullable, string, max:255
- **pack_id**: nullable, exists:media_packs,id

### Media Images (Generate)
- **prompt**: required, string, max:1000
- **aspect_ratio**: required, in:1:1,16:9,9:16,4:3
- **filename**: nullable, string, max:255
- **pack_id**: nullable, exists:media_packs,id
- **model**: nullable, string

### Media Images (Update)
- **filename**: sometimes, required, string, max:255
- **pack_id**: nullable, exists:media_packs,id

---

## Authorization

All endpoints require:
1. **Authentication**: Valid Sanctum token in `Authorization: Bearer {token}` header
2. **Organization Context**: `X-Organization-ID` header matching user's organization
3. **Ownership**: Resources must belong to the authenticated user's organization

### Middleware Implementation

**File:** `app/Http/Middleware/EnsureOrganizationAccess.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureOrganizationAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $organizationId = $request->header('X-Organization-ID');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Organization ID required'], 400);
        }

        // Verify user has access to this organization
        $organization = $user->organizations()->find($organizationId);
        
        if (!$organization) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Attach organization to request for easy access in controllers
        $request->attributes->set('organization', $organization);
        $user->currentOrganization = $organization;

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... other middleware
    'organization' => \App\Http\Middleware\EnsureOrganizationAccess::class,
];
```

Update routes:

```php
Route::prefix('v1')->middleware(['auth:sanctum', 'organization'])->group(function () {
    // All media routes...
});
```

---

## Error Handling

### Common Error Responses

#### 401 Unauthorized
```json
{
  "message": "Unauthenticated"
}
```

#### 403 Forbidden
```json
{
  "message": "Access denied"
}
```

#### 404 Not Found
```json
{
  "message": "Resource not found"
}
```

#### 422 Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required."],
    "pack_id": ["The selected pack id is invalid."]
  }
}
```

#### 500 Server Error
```json
{
  "message": "Failed to upload image",
  "error": "Detailed error message"
}
```

---

## Testing

### Feature Tests

**File:** `tests/Feature/MediaPackTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Organization;
use App\Models\MediaPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaPackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->user->organizations()->attach($this->organization);
    }

    public function test_can_list_media_packs()
    {
        MediaPack::factory()->count(3)->create([
            'organization_id' => $this->organization->id
        ]);

        $response = $this->actingAs($this->user)
            ->withHeader('X-Organization-ID', $this->organization->id)
            ->getJson('/api/v1/media/packs');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_media_pack()
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Organization-ID', $this->organization->id)
            ->postJson('/api/v1/media/packs', [
                'name' => 'Test Pack',
                'description' => 'Test Description'
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'name' => 'Test Pack',
                'description' => 'Test Description'
            ]);

        $this->assertDatabaseHas('media_packs', [
            'name' => 'Test Pack',
            'organization_id' => $this->organization->id
        ]);
    }

    public function test_can_update_media_pack()
    {
        $pack = MediaPack::factory()->create([
            'organization_id' => $this->organization->id
        ]);

        $response = $this->actingAs($this->user)
            ->withHeader('X-Organization-ID', $this->organization->id)
            ->patchJson("/api/v1/media/packs/{$pack->id}", [
                'name' => 'Updated Name'
            ]);

        $response->assertStatus(200)
            ->assertJson(['name' => 'Updated Name']);
    }

    public function test_can_delete_media_pack()
    {
        $pack = MediaPack::factory()->create([
            'organization_id' => $this->organization->id
        ]);

        $response = $this->actingAs($this->user)
            ->withHeader('X-Organization-ID', $this->organization->id)
            ->deleteJson("/api/v1/media/packs/{$pack->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('media_packs', ['id' => $pack->id]);
    }
}
```

---

## Performance Considerations

### Indexing
Ensure proper database indexes exist:
```sql
CREATE INDEX idx_media_packs_org_created ON media_packs(organization_id, created_at);
CREATE INDEX idx_media_images_org_created ON media_images(organization_id, created_at);
CREATE INDEX idx_media_images_pack ON media_images(pack_id);
```

### Pagination
Always paginate large result sets:
```php
$images = $query->paginate(50); // Default 50 items per page
```

### Eager Loading
Load relationships to avoid N+1 queries:
```php
$images = MediaImage::with('pack')->get();
```

### Caching
Consider caching pack counts:
```php
Cache::remember("pack_{$packId}_count", 3600, function () use ($packId) {
    return MediaImage::where('pack_id', $packId)->count();
});
```

---

## Summary

This document provides:

1. ✅ **Complete Media Packs CRUD** (Create, Read, Update, Delete)
2. ✅ **Complete Media Images CRUD** (List, Show, Upload, Generate, Update, Delete)
3. ✅ **Database migrations and models**
4. ✅ **Full controller implementations**
5. ✅ **Route definitions**
6. ✅ **Validation rules**
7. ✅ **Authorization middleware**
8. ✅ **Request/response examples**
9. ✅ **Error handling**
10. ✅ **Feature tests**
11. ✅ **Performance considerations**

All endpoints are now documented and ready for implementation in Laravel! 🚀
