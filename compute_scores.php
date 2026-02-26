<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use LaundryOS\LeadScoring\Jobs\ComputeLeadScoresJob;
use LaundryOS\LeadScoring\Models\IcpProfile;

$icp = IcpProfile::find('019bf56e-55a8-7205-b99a-7aa24bfed433');

if (!$icp) {
    echo "ICP Profile not found\n";
    exit(1);
}

echo "Found ICP Profile: {$icp->name}\n";
echo "Dispatching ComputeLeadScoresJob...\n";

dispatch_sync(new ComputeLeadScoresJob($icp));

echo "Done! Checking score count...\n";

$count = \Illuminate\Support\Facades\DB::table('lw_lead_scores')->count();
echo "Total lead scores: $count\n";
