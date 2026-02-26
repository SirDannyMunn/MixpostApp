# Production Server Debug Instructions

This codebase is used for multiple projects and serves as a shared Laravel 12 backend.

## Server Access

Connect to the production server:

```bash
ssh velocity
```

The server hosts two applications:

| Domain | Product | Frontend | Description |
|---|---|---|---|
| `api.meetreagan.com` | LinkedIn Automation / AI SDR | `./lw_frontend` | Lead discovery, LinkedIn automation, AI sales development |
| `api.tryvelocity.app` | AI Ghostwriting Tool | `./frontend` | Social media content generation, scheduling, AI canvas |

Both share the same Laravel backend, database, and queue infrastructure.

---

## Stack Overview

- **Framework:** Laravel 12 / PHP 8.4
- **Database:** PostgreSQL 16 with pgvector extension
- **Cache / Queues:** Redis
- **Queue Dashboard:** Laravel Horizon
- **Web Server:** Nginx + PHP-FPM (`php8.4-fpm`)
- **Auth:** Sanctum (token-based) + Passport (OAuth2)

---

## Log Files

All logs are in `storage/logs/`:

| File | What it captures |
|---|---|
| `laravel.log` | Main application log (default channel) |
| `http.log` | HTTP request/response log (custom formatter) |
| `topic-intelligence.log` | Topic intelligence / social watcher activity |

### Viewing Logs

```bash
# Tail the main log in real time
tail -f storage/logs/laravel.log

# Search for errors in the last 500 lines
tail -500 storage/logs/laravel.log | grep -i "error\|exception"

# Tail HTTP request logs
tail -f storage/logs/http.log

# View today's topic intelligence log
tail -f storage/logs/topic-intelligence.log
```

### Log Levels

The `LOG_LEVEL` env var controls verbosity. In production this is typically `info` or `warning`. To temporarily enable debug logging:

```bash
# In the .env file, change:
LOG_LEVEL=debug

# Then reload PHP-FPM for changes to take effect:
echo "" | sudo -S service php8.4-fpm reload
```

> Remember to revert `LOG_LEVEL` after debugging to avoid filling the disk.

---

## Queue & Horizon

Queues are processed by Laravel Horizon using Redis. Horizon manages two supervisor groups:

| Supervisor | Connection | Queue | Max Processes | Timeout |
|---|---|---|---|---|
| `supervisor-1` | `redis` | `default` | 10 | 60s |
| `mixpost-heavy` | `mixpost-redis` | `publish-post` | 3 | 60 min |

### Common Queue Commands

```bash
# Check Horizon status
php artisan horizon:status

# Restart all queue workers (graceful)
php artisan queue:restart

# Restart Horizon (re-provisions supervisors)
php artisan horizon:terminate
php artisan horizon

# View failed jobs
php artisan queue:failed

# Retry a specific failed job
php artisan queue:retry <job-uuid>

# Retry all failed jobs
php artisan queue:retry all

# Flush all failed jobs
php artisan queue:flush
```

### Horizon Dashboard

Horizon's web dashboard is available at the `/horizon` path on the application URL. It provides real-time views of:
- Active/pending/completed/failed jobs
- Queue throughput metrics
- Worker process status

---

## Database

PostgreSQL with pgvector for vector similarity search (embeddings).

### Connecting to the Database

```bash
# Use Laravel's DB credentials from .env
php artisan tinker --execute="echo config('database.connections.pgsql.database');"

# Or connect directly via psql (check .env for credentials)
psql -h 127.0.0.1 -U <DB_USERNAME> -d <DB_DATABASE>
```

### Useful Database Checks

```bash
# Verify pgvector extension is loaded
php artisan db:verify:pgvector

# Run pending migrations
php artisan migrate --force

# Check migration status
php artisan migrate:status
```

---

## Deployment

Deployments are handled via `deploy.sh` in the project root:

```bash
#!/bin/bash
git pull origin main
git submodule update --init --recursive
composer install --no-interaction --prefer-dist --optimize-autoloader
echo "" | sudo -S service php8.4-fpm reload
php artisan migrate --force
php artisan queue:restart
```

To deploy manually, run:

```bash
bash deploy.sh
```

### What the Deploy Script Does

1. Pulls latest code from `main`
2. Updates git submodules (frontend repos)
3. Installs/updates Composer dependencies with autoloader optimization
4. Reloads PHP-FPM to pick up new code (no downtime)
5. Runs any pending database migrations
6. Gracefully restarts queue workers so they pick up new code

### Auto deploy

Any changes pushed to main from the backend repository to the origin remote (origin https://github.com/SirDannyMunn/velocity.git) will be automatically deployed to prod. 

---

## Debugging Techniques

### Ad-hoc PHP Scripts (Tinker Debug)

Do **not** use `php artisan tinker --execute`. Use the `tinker-debug` package instead:

1. Create a script at `Scratch/<script_name>.php`
2. Run it: `php artisan tinker-debug:run <script_name>` (no `.php` extension)

Example:

```php
// Scratch/check_user.php
<?php
$user = \App\Models\User::find(1);
dump($user->email, $user->organizations->pluck('name'));
```

```bash
php artisan tinker-debug:run check_user
```

### Checking Application Health

```bash
# Verify the app boots without errors
php artisan about

# List all registered routes
php artisan route:list

# Check current environment
php artisan env

# Clear all caches (use when config/routes seem stale)
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
```

### Common Artisan Debug Commands

```bash
# List users and org IDs for debugging auth/org issues
php artisan dev:ids:list

# Inspect a content plan
php artisan content-plan:inspect <plan_id>

# List AI generation snapshots (useful for debugging AI output)
php artisan ai:snapshots:list --limit=20

# View the full prompt that was sent for a snapshot
php artisan ai:prompts:show <snapshot_id>

# Replay a snapshot to reproduce AI generation
php artisan ai:snapshots:replay <snapshot_id>

# Check LLM usage/accounting
php artisan llm:accounting-status

# Get a content service report
php artisan content-service:report:get <snapshot_id>

# Verify pgvector is working
php artisan db:verify:pgvector
```

### Checking PHP-FPM

```bash
# Check if PHP-FPM is running
sudo service php8.4-fpm status

# Reload PHP-FPM (picks up code changes without downtime)
echo "" | sudo -S service php8.4-fpm reload

# Restart PHP-FPM (full restart, brief downtime)
echo "" | sudo -S service php8.4-fpm restart
```

### Checking Redis

```bash
# Test Redis connectivity
redis-cli ping
# Expected: PONG

# Monitor Redis commands in real time (useful for queue debugging)
redis-cli monitor

# Check Redis memory usage
redis-cli info memory
```

### Checking Nginx

```bash
# Test nginx configuration
sudo nginx -t

# View nginx error log
tail -f /var/log/nginx/error.log

# View nginx access log for the relevant domain
tail -f /var/log/nginx/access.log
```

---

## Troubleshooting Common Issues

### 500 Errors / White Screen

1. Check `storage/logs/laravel.log` for the stack trace
2. Verify `.env` exists and `APP_KEY` is set
3. Ensure `storage/` and `bootstrap/cache/` are writable:
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### Queue Jobs Not Processing

1. Check Horizon status: `php artisan horizon:status`
2. Check if Redis is running: `redis-cli ping`
3. Look at failed jobs: `php artisan queue:failed`
4. Restart workers: `php artisan queue:restart`
5. If Horizon crashed, restart it (check if it's managed by systemd/supervisor)

### API Returning 401 Unauthorized

1. Verify the `Authorization: Bearer <token>` header is being sent
2. Check if the token is valid in the database (`personal_access_tokens` or `oauth_access_tokens` table)
3. Ensure `auth:sanctum,api` middleware is applied to the route
4. Check that the `X-Organization-ID` header is present for org-scoped routes

### AI Generation Failing

1. Check OpenRouter API key is set: verify `OPENROUTER_API_KEY` in `.env`
2. View the snapshot for the failed generation: `php artisan ai:snapshots:list`
3. Inspect the prompt: `php artisan ai:prompts:show <id>`
4. Replay the snapshot to test: `php artisan ai:snapshots:replay <id>`
5. Check `laravel.log` for `openrouter.*` or `ai.*` log entries

### Database Connection Issues

1. Verify PostgreSQL is running: `sudo service postgresql status`
2. Check `.env` credentials match the database
3. Test the connection: `php artisan migrate:status`
4. Verify pgvector: `php artisan db:verify:pgvector`

### Post-Deploy Issues

If something breaks after a deploy:

1. Check if migrations ran successfully: `php artisan migrate:status`
2. Clear all caches:
   ```bash
   php artisan config:clear && php artisan route:clear && php artisan cache:clear && php artisan view:clear
   ```
3. Reload PHP-FPM: `echo "" | sudo -S service php8.4-fpm reload`
4. Restart Horizon: `php artisan horizon:terminate && php artisan horizon`
5. Check `storage/logs/laravel.log` for errors
