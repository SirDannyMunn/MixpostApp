<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\AIImageGenerationService;

/**
 * Quick tinker test: Generate an image via current AI implementation
 * Usage (with tinker-debug): see package README. Alternatively:
 *   php artisan tinker --execute="require 'tinker/debug/ai_generate.php';"
 */


$org = Organization::query()->firstOrFail();
$user = User::query()->firstOrFail();

$service = app(AIImageGenerationService::class);

$prompt = env('TEST_IMAGE_PROMPT', 'merry christmas');
$aspect = env('TEST_IMAGE_ASPECT', '1:1');
$model = env('TEST_IMAGE_MODEL');

$image = $service->generateAndSaveImage(
    $org,
    $user->id,
    $prompt,
    $aspect,
    null,
    null,
    $model,
);
dd($image);

dump('Generated image ID: '.$image->id);
dump($image->toArray());
