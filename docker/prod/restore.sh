#!/bin/sh
# Restores a backup made by backup.sh into the PRODUCTION stack. REPLACES the current
# database contents and uploaded PDFs. Run on the host, from the repository root:
#
#   sh docker/prod/restore.sh backups/askmydocs-<time>.dump --yes
#
# The matching .files.tgz (same name) is restored too when it exists.
# The web server and workers are stopped during the restore and started again afterwards.
set -eu

DUMP="${1:-}"
if [ -z "$DUMP" ] || [ ! -f "$DUMP" ] || [ "${2:-}" != "--yes" ]; then
    echo "Usage: sh docker/prod/restore.sh <backup.dump> --yes" >&2
    echo "This replaces the current database and uploaded files." >&2
    exit 1
fi
FILES="${DUMP%.dump}.files.tgz"

COMPOSE="docker compose -f compose.prod.yaml --env-file .env.production"

# Nothing may write while the data is replaced.
$COMPOSE stop web worker-answers worker-documents scheduler

# Single quotes: the variables are expanded in the database container.
# shellcheck disable=SC2016
$COMPOSE exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' \
    < "$DUMP"

if [ -f "$FILES" ]; then
    $COMPOSE exec -T app sh -c 'find /app/storage/app -mindepth 1 -delete && tar -C /app/storage/app -xzf -' \
        < "$FILES"
fi

$COMPOSE start web worker-answers worker-documents scheduler
echo "Restored $DUMP"
