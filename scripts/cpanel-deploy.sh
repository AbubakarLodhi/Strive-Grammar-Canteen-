#!/usr/bin/env bash
# cPanel deploy helper for Strive Uniform and Bookshop (Laravel).
# Called from .cpanel.yml after "Deploy HEAD Commit".
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Optional local overrides (not committed). Copy from deploy/config.example.
if [[ -f "$ROOT/deploy/config" ]]; then
  # shellcheck disable=SC1091
  source "$ROOT/deploy/config"
fi

DEPLOY_PATH="${DEPLOY_PATH:-$HOME/public_html}"
PHP_BIN="${PHP_BIN:-}"

if [[ -z "$PHP_BIN" ]]; then
  for candidate in \
    /opt/cpanel/ea-php84/root/usr/bin/php \
    /usr/local/bin/ea-php84 \
    /opt/cpanel/ea-php83/root/usr/bin/php \
    /usr/local/bin/ea-php83 \
    /opt/cpanel/ea-php82/root/usr/bin/php \
    "$(command -v php || true)"
  do
    if [[ -n "$candidate" && -x "$candidate" ]]; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi

if [[ -z "${PHP_BIN}" || ! -x "${PHP_BIN}" ]]; then
  echo "ERROR: PHP CLI not found. Set PHP_BIN in deploy/config (e.g. /usr/local/bin/ea-php84)."
  exit 1
fi

COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"
if [[ -z "${COMPOSER_BIN}" ]]; then
  if [[ -x "$HOME/bin/composer" ]]; then
    COMPOSER_BIN="$HOME/bin/composer"
  elif [[ -f "$HOME/composer.phar" ]]; then
    COMPOSER_BIN="$PHP_BIN $HOME/composer.phar"
  fi
fi

echo "Deploying from: $ROOT"
echo "Deploying to:   $DEPLOY_PATH"
echo "PHP:            $PHP_BIN"

mkdir -p "$DEPLOY_PATH"

# Preserve production secrets, uploaded vendor/, and writable storage across deploys.
/bin/tar \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='deploy/config' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='vendor.zip' \
  --exclude='storage/app' \
  --exclude='storage/framework/cache' \
  --exclude='storage/framework/sessions' \
  --exclude='storage/framework/views' \
  --exclude='storage/logs' \
  --exclude='public/hot' \
  --exclude='.github' \
  --exclude='tests' \
  -cf - . | (cd "$DEPLOY_PATH" && /bin/tar -xf -)

cd "$DEPLOY_PATH"

# Shared hosting: document root is public_html (project root), so expose public assets at web root.
if [[ -d public ]]; then
  /bin/cp -R public/build public/images public/favicon* public/robots.txt . 2>/dev/null || true
  [[ -f public/css ]] || true
  /bin/cp -R public/css . 2>/dev/null || true
  /bin/cp -R public/js . 2>/dev/null || true
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [[ ! -f .env ]]; then
  echo "WARNING: No .env in $DEPLOY_PATH yet. Copy .env.example to .env and configure it before the site will work."
fi

# Skip composer when vendor/ was uploaded manually (no SSH hosts).
if [[ -f vendor/autoload.php && "${FORCE_COMPOSER:-0}" != "1" ]]; then
  echo "vendor/ already present — skipping composer install."
elif [[ -n "${COMPOSER_BIN}" ]]; then
  echo "Running composer install..."
  $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
else
  echo "WARNING: composer not found and vendor/ missing. Upload vendor.zip to public_html."
fi

# Cache only — migrations are run separately via Cron when SSH is unavailable.
if [[ -f artisan && -f .env ]]; then
  $PHP_BIN artisan storage:link 2>/dev/null || true
  $PHP_BIN artisan optimize:clear || true
  $PHP_BIN artisan config:cache || true
  $PHP_BIN artisan route:cache || true
  $PHP_BIN artisan view:cache || true
  $PHP_BIN artisan filament:optimize 2>/dev/null || true
fi

echo "Deploy finished."
