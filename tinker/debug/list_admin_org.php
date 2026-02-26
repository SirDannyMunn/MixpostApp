<?php

use App\Models\Organization;

$rows = Organization::query()
    ->orderBy('created_at')
    ->get(['id', 'slug', 'name'])
    ->map(function ($o) {
        return [
            'id' => (string) $o->id,
            'slug' => (string) ($o->slug ?? ''),
            'name' => (string) ($o->name ?? ''),
        ];
    })
    ->all();

$needle = 'admin';
$candidates = array_values(array_filter($rows, function ($r) use ($needle) {
    return str_contains(strtolower($r['slug']), $needle) || str_contains(strtolower($r['name']), $needle);
}));

echo "Admin-like org candidates:\n";
foreach ($candidates as $c) {
    echo "- {$c['id']}  slug={$c['slug']}  name={$c['name']}\n";
}

echo "\nAll orgs:\n";
foreach ($rows as $r) {
    echo "- {$r['id']}  slug={$r['slug']}  name={$r['name']}\n";
}
