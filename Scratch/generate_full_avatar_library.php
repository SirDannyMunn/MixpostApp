<?php

namespace Scratch;

use LaundryOS\VideoGen\Services\AvatarLibraryService;

class GenerateFullAvatarLibrary
{
    public function run()
    {
        echo "=== Generating Full Avatar Library (50+ avatars) ===\n\n";

        $service = app(AvatarLibraryService::class);

        $count = $service->getAvatarCount();
        echo "Current avatar count: {$count}\n";

        if ($count >= 50) {
            echo "✓ Library already has 50+ avatars. Skipping generation.\n";
            return;
        }

        echo "\nStarting generation...\n";
        echo "This will take approximately 10-15 minutes.\n\n";

        $startTime = microtime(true);

        try {
            $avatars = $service->generateAvatarLibrary();
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            echo "\n=== Generation Complete ===\n";
            echo "Generated: " . count($avatars) . " avatars\n";
            echo "Duration: {$duration} seconds\n";
            echo "\nBreakdown by demographics:\n";

            // Group by demographics
            $genderCounts = [];
            $ethnicityCountsMap = [];

            foreach ($avatars as $avatar) {
                $genderCounts[$avatar->gender] = ($genderCounts[$avatar->gender] ?? 0) + 1;
                $ethnicityCountsMap[$avatar->ethnicity] = ($ethnicityCountsMap[$avatar->ethnicity] ?? 0) + 1;
            }

            echo "\nBy Gender:\n";
            foreach ($genderCounts as $gender => $count) {
                echo "  {$gender}: {$count}\n";
            }

            echo "\nBy Ethnicity:\n";
            foreach ($ethnicityCountsMap as $ethnicity => $count) {
                echo "  {$ethnicity}: {$count}\n";
            }

        } catch (\Throwable $e) {
            echo "\n✗ Generation failed: {$e->getMessage()}\n";
            echo "Stack trace:\n{$e->getTraceAsString()}\n";
        }

        $finalCount = $service->getAvatarCount();
        echo "\n=== Final Stats ===\n";
        echo "Total avatars in library: {$finalCount}\n";
        echo "Target (50+): " . ($finalCount >= 50 ? "✓ ACHIEVED" : "✗ NOT YET") . "\n";
    }
}
