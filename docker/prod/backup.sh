#!/bin/sh
# Backs up the PRODUCTION stack (compose.prod.yaml): the database and the uploaded PDFs.
# Run on the host, from the repository root, while the stack is running:
#
#   sh docker/prod/backup.sh [target-folder]        # default: ./backups
#
# Creates two files named after the current time (UTC):
#   askmydocs-<time>.dump      PostgreSQL custom-format dump (restore with restore.sh)
#   askmydocs-<time>.files.tgz the uploaded PDFs (storage/app)
#
# Backups contain user documents and password hashes: keep them private, store a copy
# off the server, and never commit them.
set -eu

COMPOSE="docker compose -f compose.prod.yaml --env-file .env.production"
TARGET="${1:-backups}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

mkdir -p "$TARGET"
umask 077

# The credentials come from the database container's own environment, so no password
# appears on the command line or in this script (single quotes: expanded in the container).
# shellcheck disable=SC2016
$COMPOSE exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' \
    > "$TARGET/askmydocs-$STAMP.dump"

$COMPOSE exec -T app tar -C /app/storage/app -czf - . \
    > "$TARGET/askmydocs-$STAMP.files.tgz"

echo "Backup written to $TARGET/askmydocs-$STAMP.dump and .files.tgz"
