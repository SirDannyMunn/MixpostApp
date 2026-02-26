# 0) Optional: clear worker log for a clean read
Set-Content storage/logs/browseruse-worker.log ''

# 1) Task 1 (creates session)
$out1 = php artisan phantombrowse:submit-task browseruse-local-service "go to example.com" --profile-id=8e5f4a4a-9ff9-4721-aa02-3e287f6e6711 --max-steps=30 --wait --timeout=180 --poll=2
$out1

# 2) Extract session id from task 1 output
$sid = ($out1 | Select-String '^Session:\s+(.+)$').Matches[0].Groups[1].Value.Trim()
"SESSION_ID=$sid"

# 3) Task 2 on SAME session
$out2 = php artisan phantombrowse:submit-task browseruse-local-service "go to usewebmania.com" --session-id=$sid --max-steps=30 --wait --timeout=180 --poll=2
$out2

# 4) Verify both tasks/session in DB (replace task ids if needed)
$task1 = ($out1 | Select-String '^Task created:\s+(.+)$').Matches[0].Groups[1].Value.Trim()
$task2 = ($out2 | Select-String '^Task created:\s+(.+)$').Matches[0].Groups[1].Value.Trim()
php artisan tinker --execute="dump(\LaundryOS\PhantomBrowseCore\Models\Task::query()->find('$task1')->only(['id','session_id','status','is_success','failure_reason','metadata'])); dump(\LaundryOS\PhantomBrowseCore\Models\Task::query()->find('$task2')->only(['id','session_id','status','is_success','failure_reason','metadata']));"

# 5) Verify worker log for this run only
rg -n "$sid|$task1|$task2|rebrowser_run_health|browser_cache_|Final Result|task_failed|agent_reported_failure" storage/logs/browseruse-worker.log -S
