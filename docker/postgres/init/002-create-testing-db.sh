#!/usr/bin/env bash
set -euo pipefail

# This script runs via /docker-entrypoint-initdb.d on first init of the Postgres data volume.
# It creates an additional database for PHPUnit/Laravel tests.
#
# Convention:
#   - Primary DB:   $POSTGRES_DB
#   - Test DB:      ${POSTGRES_DB}_testing

PRIMARY_DB="${POSTGRES_DB:-}"
if [[ -z "$PRIMARY_DB" ]]; then
  echo "POSTGRES_DB is not set; skipping additional DB creation"
  exit 0
fi

TEST_DB="${PRIMARY_DB}_testing"

echo "Ensuring additional database exists: ${TEST_DB}"

# Create DB if missing (Postgres doesn't have CREATE DATABASE IF NOT EXISTS)
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "postgres" <<-SQL
  SELECT format('CREATE DATABASE %I', '${TEST_DB}')
  WHERE NOT EXISTS (
    SELECT 1 FROM pg_database WHERE datname = '${TEST_DB}'
  );
\gexec
SQL
