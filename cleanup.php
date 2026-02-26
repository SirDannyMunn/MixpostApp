<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();

$orgId = "019c4d02-4712-73bc-9e40-d614f6322d7e";
$cutoff = "2026-02-11 20:30:00";

$keepIds = DB::table("lw_lead_scores")->where("organization_id", $orgId)->where("created_at", ">=", $cutoff)->pluck("lead_id")
    ->merge(DB::table("lw_intent_signals")->where("organization_id", $orgId)->where("created_at", ">=", $cutoff)->pluck("lead_id"))
    ->unique()->values()->toArray();
echo "Keeping: " . count($keepIds) . PHP_EOL;

$d1 = DB::table("lw_lead_scores")->where("organization_id", $orgId)->whereNotIn("lead_id", $keepIds)->delete();
echo "Scores deleted: $d1" . PHP_EOL;

$d2 = DB::table("lw_intent_signals")->where("organization_id", $orgId)->whereNotIn("lead_id", $keepIds)->delete();
echo "Signals deleted: $d2" . PHP_EOL;

$oldIds = DB::table("lw_lead_organization")->where("organization_id", $orgId)->whereNotIn("lead_id", $keepIds)->pluck("lead_id")->toArray();
echo "Old pivots: " . count($oldIds) . PHP_EOL;

DB::table("lw_lead_provenance")->whereIn("lead_id", $oldIds)->delete();
echo "Provenance done" . PHP_EOL;

DB::table("lw_lead_identities")->whereIn("lead_id", $oldIds)->delete();
echo "Identities done" . PHP_EOL;

DB::table("lw_lead_organization")->where("organization_id", $orgId)->whereNotIn("lead_id", $keepIds)->delete();
echo "Pivots done" . PHP_EOL;

$orphans = DB::table("lw_leads")->whereIn("id", $oldIds)->whereNotIn("id", DB::table("lw_lead_organization")->select("lead_id"))->delete();
echo "Orphan leads deleted: $orphans" . PHP_EOL;

$remaining = DB::table("lw_lead_organization")->where("organization_id", $orgId)->count();
echo "Remaining: $remaining" . PHP_EOL;
