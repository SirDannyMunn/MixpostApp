<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mixpost_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->longText('configuration');
            $table->boolean('active')->default(false);
        });

        Schema::create('mixpost_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('username')->nullable();
            $table->jsonb('media')->nullable();
            $table->string('provider');
            $table->string('provider_id');
            $table->jsonb('data')->nullable();
            $table->boolean('authorized')->default(false);
            $table->longText('access_token');
            $table->timestamps();

            $table->unique(['provider', 'provider_id'], 'accounts_unq_id');
        });

        Schema::create('mixpost_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->smallInteger('status')->default(0);
            $table->smallInteger('schedule_status')->default(0);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('mixpost_post_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('mixpost_posts')->onDelete('cascade');
            $table->foreignUuid('account_id')->constrained('mixpost_accounts')->onDelete('cascade');
            $table->string('provider_post_id')->nullable();
            $table->jsonb('data')->nullable();
            $table->jsonb('errors')->nullable();
        });

        Schema::create('mixpost_post_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('mixpost_posts')->onDelete('cascade');
            $table->uuid('account_id');
            $table->smallInteger('is_original')->default(0);
            $table->jsonb('content')->nullable();
        });

        Schema::create('mixpost_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('hex_color', 6);
            $table->timestamps();
        });

        Schema::create('mixpost_tag_post', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained('mixpost_tags')->onDelete('cascade');
            $table->foreignUuid('post_id')->constrained('mixpost_posts')->onDelete('cascade');
            $table->unique(['tag_id','post_id']);
        });

        Schema::create('mixpost_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('mime_type');
            $table->string('disk');
            $table->string('path');
            $table->jsonb('data')->nullable();
            $table->bigInteger('size');
            $table->bigInteger('size_total'); // including conversions
            $table->jsonb('conversions')->nullable();
            $table->timestamps();
        });

        Schema::create('mixpost_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('payload');
        });

        Schema::create('mixpost_imported_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('mixpost_accounts')->onDelete('cascade');
            $table->string('provider_post_id')->index();
            $table->jsonb('content');
            $table->jsonb('metrics');
            $table->dateTime('created_at');

            $table->unique(['account_id', 'provider_post_id'], 'imported_posts_unq_id');
        });

        Schema::create('mixpost_facebook_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('mixpost_accounts')->onDelete('cascade');
            $table->integer('type');
            $table->integer('value');
            $table->date('date');
            $table->timestamps();

            $table->unique(['account_id', 'type', 'date'], 'fb_insights_unq_id');
        });

        Schema::create('mixpost_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('mixpost_accounts')->onDelete('cascade');
            $table->jsonb('data');
            $table->date('date');

            $table->unique(['account_id', 'date'], 'metrics_unq_id');
        });

        Schema::create('mixpost_audience', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('mixpost_accounts')->onDelete('cascade');
            $table->integer('total')->default(0);
            $table->date('date');

            $table->index(['account_id', 'date'], 'audience_entry_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mixpost_audience');
        Schema::dropIfExists('mixpost_metrics');
        Schema::dropIfExists('mixpost_facebook_insights');
        Schema::dropIfExists('mixpost_imported_posts');
        Schema::dropIfExists('mixpost_settings');
        Schema::dropIfExists('mixpost_media');
        Schema::dropIfExists('mixpost_tag_post');
        Schema::dropIfExists('mixpost_tags');
        Schema::dropIfExists('mixpost_post_versions');
        Schema::dropIfExists('mixpost_post_accounts');
        Schema::dropIfExists('mixpost_posts');
        Schema::dropIfExists('mixpost_accounts');
        Schema::dropIfExists('mixpost_services');
    }
};
