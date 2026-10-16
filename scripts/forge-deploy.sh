#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

php_bin="${FORGE_PHP:-php8.3}"
composer_bin="${FORGE_COMPOSER:-composer}"
fpm_service="${FORGE_PHP_FPM:-php8.3-fpm}"

# Forge may supply "php8.3 /usr/local/bin/composer" rather than one executable.
# Preserve an exact executable path (including spaces); otherwise split Forge's
# whitespace-separated command into argv. Never evaluate shell syntax here.
if command -v -- "$composer_bin" >/dev/null 2>&1; then
    composer_command=("$composer_bin")
else
    read -r -a composer_command <<< "$composer_bin"
fi
"${composer_command[@]}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

# Config clearing only removes a file; cache clearing needs a migrated database.
# Read the current .env before migrating, especially after changing DB_CONNECTION.
"$php_bin" artisan config:clear
"$php_bin" artisan migrate --force
"$php_bin" artisan cache:clear
"$php_bin" artisan view:clear
"$php_bin" artisan route:clear
ARE_DEPLOY_REVISION="$(git rev-parse HEAD)" "$php_bin" artisan optimize
"$php_bin" artisan queue:restart
# Signals the Reverb daemon through the cache; Supervisor starts it again on
# the new code. Harmless when Reverb is not enabled yet.
"$php_bin" artisan reverb:restart

# Production disables opcode timestamp checks. Clearing Blade caches is insufficient.
sudo -n service "$fpm_service" reload

# queue:restart does not restart Horizon. Verify actual per-lane execution
# after terminating the old master. Keep the deploy check within Forge's
# ten-minute deployment limit; a long encode may require manual verification.
"$php_bin" artisan queue:verify-deployment --restart --timeout=90
