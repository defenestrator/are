#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

php_bin="${FORGE_PHP:-php8.3}"
composer_bin="${FORGE_COMPOSER:-composer}"
fpm_service="${FORGE_PHP_FPM:-php8.3-fpm}"

"$composer_bin" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

# Config clearing only removes a file; cache clearing needs a migrated database.
# Read the current .env before migrating, especially after changing DB_CONNECTION.
"$php_bin" artisan config:clear
"$php_bin" artisan migrate --force
"$php_bin" artisan cache:clear
"$php_bin" artisan view:clear
"$php_bin" artisan route:clear
"$php_bin" artisan optimize
"$php_bin" artisan queue:restart
# Signals the Reverb daemon through the cache; Supervisor starts it again on
# the new code. Harmless when Reverb is not enabled yet.
"$php_bin" artisan reverb:restart

# Production disables opcode timestamp checks. Clearing Blade caches is insufficient.
sudo -n service "$fpm_service" reload
