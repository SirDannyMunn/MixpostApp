<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ContentPlan;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

try {
    $plan = ContentPlan::create([
        'organization_id' => Organization::first()->id,
        'user_id' => User::first()->id,
        'conversation_id' => (string) Str::uuid(),
        'name' => 'Build In Public - Linkedin (14 days)',
        'plan_type' => 'build_in_public',
        'duration_days' => 14,
        'platform' => 'linkedin',
        'goal' => 'Test goal to introduce software product',
        'audience' => 'Content creators and business owners',
        'status' => 'confirmed',
    ]);
    
    echo "✅ SUCCESS: Plan created with ID: {$plan->id}\n";
    echo "   Name: {$plan->name}\n";
    echo "   Platform: {$plan->platform}\n";
    echo "   Duration: {$plan->duration_days} days\n";
    
} catch (\Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    exit(1);
}
