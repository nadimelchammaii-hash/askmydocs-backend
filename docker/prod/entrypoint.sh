#!/bin/sh
# Entrypoint of the production PHP image (API, queue workers, scheduler).
# Caches configuration, routes, events and views at start: the configuration comes from
# the container's environment variables, which are only known at run time.
set -eu

php artisan optimize --no-interaction

exec "$@"
