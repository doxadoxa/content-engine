#!/bin/bash
# Runs once, on first boot of an empty data volume.
#
# The suite runs against Postgres rather than sqlite (pgvector, jsonb, partial
# indexes are what the engine stands on from phase 3 onward), so it needs its
# own database — otherwise `php artisan test` wipes the development data.
#
# The `vector` extension is created here as well as by a migration. Both are
# needed: this covers databases that exist before any migration runs, the
# migration covers every other environment (CI, production) where this script
# never executes.
set -e

TEST_DB="${POSTGRES_DB}_test"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "postgres" <<-EOSQL
	SELECT 'CREATE DATABASE "${TEST_DB}"'
	WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec
EOSQL

for db in "$POSTGRES_DB" "$TEST_DB"; do
	psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" \
		-c 'CREATE EXTENSION IF NOT EXISTS vector;'
done

echo "postgres: ${POSTGRES_DB} and ${TEST_DB} ready, pgvector enabled"
