<?php
/**
 * Package Split Test Script
 * 
 * Tests that all 4 new packages are properly installed and working.
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Testing Package Split ===" . PHP_EOL . PHP_EOL;

$passed = 0;
$failed = 0;

function test($name, callable $fn) {
    global $passed, $failed;
    try {
        $fn();
        echo "   ✓ {$name}" . PHP_EOL;
        $passed++;
    } catch (Throwable $e) {
        echo "   ✗ {$name}: " . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

// Test 1: Lead Intelligence
echo "1. Lead Intelligence Package:" . PHP_EOL;
test("Lead model", fn() => new \LaundryOS\LeadIntelligence\Models\Lead());
test("Company model", fn() => new \LaundryOS\LeadIntelligence\Models\Company());
test("LeadIdentity model", fn() => new \LaundryOS\LeadIntelligence\Models\LeadIdentity());
test("Interaction model", fn() => new \LaundryOS\LeadIntelligence\Models\Interaction());
test("LeadResolver service", fn() => app(\LaundryOS\LeadIntelligence\Services\LeadResolver::class));
test("LeadStatus enum", fn() => \LaundryOS\LeadIntelligence\Enums\LeadStatus::New);
echo PHP_EOL;

// Test 2: Lead Scoring
echo "2. Lead Scoring Package:" . PHP_EOL;
test("IcpProfile model", fn() => new \LaundryOS\LeadScoring\Models\IcpProfile());
test("IntentSignal model", fn() => new \LaundryOS\LeadScoring\Models\IntentSignal());
test("LeadScore model", fn() => new \LaundryOS\LeadScoring\Models\LeadScore());
test("LeadQueue model", fn() => new \LaundryOS\LeadScoring\Models\LeadQueue());
test("LeadScorer service", fn() => app(\LaundryOS\LeadScoring\Services\LeadScorer::class));
test("IntentDeriver service", fn() => app(\LaundryOS\LeadScoring\Services\IntentDeriver::class));
echo PHP_EOL;

// Test 3: Lead Discovery
echo "3. Lead Discovery Package:" . PHP_EOL;
test("Agent model", fn() => new \LaundryOS\LeadDiscovery\Models\Agent());
test("AgentRun model", fn() => new \LaundryOS\LeadDiscovery\Models\AgentRun());
test("SearchRun model", fn() => new \LaundryOS\LeadDiscovery\Models\SearchRun());
test("Competitor model", fn() => new \LaundryOS\LeadDiscovery\Models\Competitor());
test("TrackedInfluencer model", fn() => new \LaundryOS\LeadDiscovery\Models\TrackedInfluencer());
test("KeywordSet model", fn() => new \LaundryOS\LeadDiscovery\Models\KeywordSet());
test("AgentScheduler service", fn() => app(\LaundryOS\LeadDiscovery\Services\AgentScheduler::class));
echo PHP_EOL;

// Test 4: LinkedIn Automation
echo "4. LinkedIn Automation Package:" . PHP_EOL;
test("LinkedInConnectionService", fn() => app(\LaundryOS\LinkedInAutomation\Services\LinkedInConnectionService::class));
test("LinkedInAutomationErrorService", fn() => app(\LaundryOS\LinkedInAutomation\Services\LinkedInAutomationErrorService::class));
echo PHP_EOL;

// Test 5: Backward Compatibility Aliases
echo "5. Backward Compatibility (Class Aliases):" . PHP_EOL;
test("Old Lead namespace", fn() => class_exists(\LaundryOS\LeadWatcher\Models\Lead::class) || throw new Exception("Alias not working"));
test("Old IcpProfile namespace", fn() => class_exists(\LaundryOS\LeadWatcher\Models\IcpProfile::class) || throw new Exception("Alias not working"));
test("Old Agent namespace", fn() => class_exists(\LaundryOS\LeadWatcher\Models\Agent::class) || throw new Exception("Alias not working"));
test("Old LeadScorer namespace", fn() => class_exists(\LaundryOS\LeadWatcher\Services\LeadScorer::class) || throw new Exception("Alias not working"));
test("Old LeadStatus enum", fn() => class_exists(\LaundryOS\LeadWatcher\Enums\LeadStatus::class) || throw new Exception("Alias not working"));
echo PHP_EOL;

// Test 6: Routes registered
echo "6. Routes Registered:" . PHP_EOL;
$router = app('router');
$routes = collect($router->getRoutes())->map(fn($r) => $r->uri())->toArray();
test("Lead routes", fn() => in_array('api/v1/lead-watcher/leads', $routes) || throw new Exception("Route not found"));
test("ICP routes", fn() => in_array('api/v1/lead-watcher/icp-profiles', $routes) || throw new Exception("Route not found"));
test("Agent routes", fn() => in_array('api/v1/lead-watcher/agents', $routes) || throw new Exception("Route not found"));
test("Competitor routes", fn() => in_array('api/v1/lead-watcher/competitors', $routes) || throw new Exception("Route not found"));
test("Queue routes", fn() => in_array('api/v1/lead-watcher/queues', $routes) || throw new Exception("Route not found"));
echo PHP_EOL;

// Test 7: Database queries work
echo "7. Database Queries:" . PHP_EOL;
test("Lead query", fn() => \LaundryOS\LeadIntelligence\Models\Lead::query()->limit(1)->get());
test("IcpProfile query", fn() => \LaundryOS\LeadScoring\Models\IcpProfile::query()->limit(1)->get());
test("Agent query", fn() => \LaundryOS\LeadDiscovery\Models\Agent::query()->limit(1)->get());
echo PHP_EOL;

// Summary
echo "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo PHP_EOL;

if ($failed > 0) {
    echo "SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
} else {
    echo "ALL TESTS PASSED!" . PHP_EOL;
    exit(0);
}
