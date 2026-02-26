<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use LaundryOS\LeadScoring\Models\IcpProfile;
use LaundryOS\LeadIntelligence\Models\Lead;
use LaundryOS\LeadScoring\Services\LeadScorer;

$icp = IcpProfile::find('019bf56e-55a8-7205-b99a-7aa24bfed433');

echo "ICP Profile: {$icp->name}\n";
echo "ICP Org ID: {$icp->organization_id}\n";

$query = Lead::forOrganization($icp->organization_id)
    ->active()
    ->with('company', 'intentSignals');

echo "Query SQL: " . $query->toSql() . "\n";
echo "Bindings: " . json_encode($query->getBindings()) . "\n";
echo "Count: " . $query->count() . "\n";

// Get leads
$leads = $query->get();
echo "Leads found: " . $leads->count() . "\n";

// Score them
$scorer = app(LeadScorer::class);
$scored = 0;
foreach ($leads as $lead) {
    $score = $scorer->scoreLeadForIcp($lead, $icp);
    echo "  Scored: {$lead->display_name} => {$score->overall_score}\n";
    $scored++;
}

echo "\nTotal scored: $scored\n";
echo "Total lead_scores in DB: " . \Illuminate\Support\Facades\DB::table('lw_lead_scores')->count() . "\n";
