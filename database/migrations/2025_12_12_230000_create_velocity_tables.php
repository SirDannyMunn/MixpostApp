<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users table adjustments
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url', 500)->nullable()->after('name');
            }
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (!Schema::hasTable('organizations')) {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_url', 500)->nullable();
            $table->enum('subscription_tier', ['free', 'pro', 'enterprise'])->default('free');
            $table->enum('subscription_status', ['active', 'cancelled', 'expired', 'trial'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('slug');
            $table->index('subscription_status');
        });
        }

        if (!Schema::hasTable('organization_members')) {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'role']);
            $table->index(['user_id', 'organization_id']);
        });
        }

        if (!Schema::hasTable('folders')) {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('icon', 50)->nullable();
            $table->integer('position')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'parent_id']);
            $table->index(['organization_id', 'position']);
        });
        // Add self-referential FK in a separate statement for PostgreSQL
        Schema::table('folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('folders')->cascadeOnDelete();
        });
        }

        if (!Schema::hasTable('tags')) {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name', 100);
            $table->string('color', 7)->default('#6b7280');
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'name']);
        });
        }

        if (!Schema::hasTable('bookmarks')) {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('url', 2000);
            $table->string('image_url', 2000)->nullable();
            $table->string('favicon_url', 2000)->nullable();
            $table->enum('platform', ['instagram', 'tiktok', 'youtube', 'twitter', 'linkedin', 'pinterest', 'other'])->default('other');
            $table->jsonb('platform_metadata')->nullable();
            $table->enum('type', ['inspiration', 'reference', 'competitor', 'trend'])->default('inspiration');
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'folder_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'platform']);
            $table->index(['organization_id', 'is_favorite']);
            $table->index(['organization_id', 'is_archived']);
        });
        }

        if (!Schema::hasTable('bookmark_tags')) {
        Schema::create('bookmark_tags', function (Blueprint $table) {
            $table->foreignUuid('bookmark_id')->constrained('bookmarks')->onDelete('cascade');
            $table->foreignUuid('tag_id')->constrained('tags')->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['bookmark_id', 'tag_id']);
            $table->index(['tag_id', 'bookmark_id']);
        });
        }

        if (!Schema::hasTable('templates')) {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('thumbnail_url', 2000)->nullable();
            $table->enum('template_type', ['slideshow', 'post', 'story', 'reel', 'custom']);
            $table->jsonb('template_data');
            $table->string('category', 100)->nullable();
            $table->boolean('is_public')->default(false);
            $table->integer('usage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'template_type']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'usage_count']);
        });
        }

        if (!Schema::hasTable('template_tags')) {
        Schema::create('template_tags', function (Blueprint $table) {
            $table->foreignUuid('template_id')->constrained('templates')->onDelete('cascade');
            $table->foreignUuid('tag_id')->constrained('tags')->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['template_id', 'tag_id']);
            $table->index(['tag_id', 'template_id']);
        });
        }

        if (!Schema::hasTable('media_packs')) {
        Schema::create('media_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->integer('image_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'created_at']);
        });
        }

        if (!Schema::hasTable('media_images')) {
        Schema::create('media_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('pack_id')->nullable()->constrained('media_packs')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('file_path', 500);
            $table->string('thumbnail_path', 500);
            $table->bigInteger('file_size');
            $table->string('mime_type', 100);
            $table->integer('width');
            $table->integer('height');
            $table->enum('generation_type', ['upload', 'ai_generated'])->default('upload');
            $table->text('ai_prompt')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'pack_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'generation_type']);
        });
        }

        if (!Schema::hasTable('projects')) {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'in_progress', 'completed', 'archived'])->default('draft');
            $table->jsonb('project_data');
            $table->string('rendered_url', 2000)->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
        }

        if (!Schema::hasTable('social_accounts')) {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('connected_by')->constrained('users')->onDelete('cascade');
            $table->enum('platform', ['instagram', 'tiktok', 'youtube', 'twitter', 'linkedin', 'facebook', 'pinterest']);
            $table->string('platform_user_id');
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->string('avatar_url', 2000)->nullable();
            $table->longText('access_token');
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'platform', 'platform_user_id'], 'unique_org_platform_account');
            $table->index(['organization_id', 'platform']);
            $table->index(['organization_id', 'is_active']);
        });
        }

        if (!Schema::hasTable('scheduled_posts')) {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->onDelete('cascade');
            $table->text('caption');
            $table->jsonb('media_urls');
            $table->timestamp('scheduled_for');
            $table->string('timezone', 50)->default('UTC');
            $table->enum('status', ['scheduled', 'publishing', 'published', 'failed', 'cancelled'])->default('scheduled');
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'scheduled_for']);
            $table->index(['organization_id', 'status']);
            $table->index(['scheduled_for', 'status']);
        });
        }

        if (!Schema::hasTable('scheduled_post_accounts')) {
        Schema::create('scheduled_post_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scheduled_post_id')->constrained('scheduled_posts')->onDelete('cascade');
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->jsonb('platform_config')->nullable();
            $table->enum('status', ['pending', 'publishing', 'published', 'failed'])->default('pending');
            $table->string('platform_post_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            // Use a short name to avoid MySQL 64-char index name limit
            $table->unique(['scheduled_post_id', 'social_account_id'], 'sp_accounts_unique');
            $table->index(['social_account_id', 'status']);
        });
        }

        if (!Schema::hasTable('social_analytics')) {
        Schema::create('social_analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->date('date');
            $table->integer('followers_count')->default(0);
            $table->integer('following_count')->default(0);
            $table->integer('posts_count')->default(0);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->bigInteger('views_count')->default(0);
            $table->bigInteger('impressions_count')->default(0);
            $table->decimal('engagement_rate', 5, 2)->default(0.00);
            $table->jsonb('raw_data')->nullable();
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();
            $table->unique(['social_account_id', 'date']);
            $table->index(['social_account_id', 'date']);
        });
        }

        if (!Schema::hasTable('activity_log')) {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('social_analytics');
        Schema::dropIfExists('scheduled_post_accounts');
        Schema::dropIfExists('scheduled_posts');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('media_images');
        Schema::dropIfExists('media_packs');
        Schema::dropIfExists('template_tags');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('bookmark_tags');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
