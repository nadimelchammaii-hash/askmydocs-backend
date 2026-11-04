#!/bin/sh
# Installs Composer dependencies into the vendor volume when they are missing or the
# lock file changed, then runs the container's command (API, worker or scheduler).
set -e

stamp=vendor/.composer-lock.sha1
current=$(sha1sum composer.lock | cut -d' ' -f1)

if [ ! -f vendor/autoload.php ] || [ "$(cat "$stamp" 2>/dev/null)" != "$current" ]; then
    # Several containers start at once: a lock directory lets only one of them install.
    if mkdir vendor/.installing 2>/dev/null; then
        trap 'rmdir vendor/.installing' EXIT
        echo "Installing Composer dependencies..."
        composer install --no-interaction --no-progress --prefer-dist
        echo "$current" > "$stamp"
        rmdir vendor/.installing
        trap - EXIT
    else
        echo "Waiting for Composer dependencies..."
        waited=0
        while [ -d vendor/.installing ]; do
            sleep 2
            waited=$((waited + 2))
            if [ "$waited" -ge 600 ]; then
                # An install was interrupted (container killed) and left its lock behind.
                echo "Still locked after 10 minutes: remove vendor/.installing in the vendor volume and restart." >&2
                exit 1
            fi
        done
    fi
fi

exec "$@"
