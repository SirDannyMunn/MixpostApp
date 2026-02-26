<?php

// Show the latest GeneratedPost status, validation, and a short preview.
// Usage:
//   php artisan tinker --execute="require 'tinker/debug/show_latest_generation.php';"

$gen = \App\Models\GeneratedPost::query()->orderByDesc('id')->first();
if (!$gen) {
    echo "No GeneratedPost records found.\n";
    return;
}

echo "ID: {$gen->id}\n";
echo "Status: {$gen->status}\n";
$content = (string) ($gen->content ?? '');
echo "Content length: " . mb_strlen($content) . "\n";
echo "Preview: " . mb_substr($content, 0, 200) . "\n";

$validation = (array) ($gen->validation ?? []);
echo "Validation: " . json_encode($validation, JSON_PRETTY_PRINT) . "\n";

$snapshot = (array) ($gen->context_snapshot ?? []);
echo "Context snapshot: " . json_encode($snapshot, JSON_PRETTY_PRINT) . "\n";

