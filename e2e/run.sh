#!/usr/bin/env bash
# End-to-end tests of the module in a real, throwaway PrestaShop: starts its
# own database and shop containers (never the dev ones), then
#
#   1. installs the last release (E2E_FROM_REF, 1.4.0 by default) and
#      configures it like a merchant, then upgrades to the current code and
#      checks the module is still active with its settings kept;
#   2. sends real signed HTTP calls to the webhook and checks the prices;
#   3. runs a sync from the back office in a browser (Playwright), against a
#      fake Pricee API running in the shop container, and checks what it got.
#
# Usage: e2e/run.sh [PrestaShop image tag] [database image]
#   e.g. e2e/run.sh 1.7.8.11 mariadb:10.6
# Env: E2E_PORT (8082), E2E_FROM_REF, E2E_DEV_IMAGE (image with Composer),
#      E2E_KEEP=1 to leave the containers running for debugging.
set -euo pipefail

PS_TAG="${1:-9.2.1-apache}"
DB_IMAGE="${2:-mariadb:11.4}"
PORT="${E2E_PORT:-8082}"
FROM_REF="${E2E_FROM_REF:-a56f73c}" # Bump version to 1.4.0
DEV_IMAGE="${E2E_DEV_IMAGE:-integration-prestashop-dev}"
# Same version as the playwright package in e2e/package.json
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="pricee-e2e-${PORT}"
DB="${NAME}-db"
SHOP="${NAME}-shop"
# PrestaShop 9 installs its admin assets in admin-dev, and renames a folder
# named "admin" (which fails on overlayfs): admin-dev works on every version
ADMIN_DIR="admin-dev"
ADMIN_EMAIL="e2e@example.com"
ADMIN_PASSWD="pricee_e2e_2026"
WORK="$(mktemp -d)"

step() { printf '\n== %s\n' "$*"; }
in_shop() { docker exec --user www-data "$SHOP" "$@"; }

cleanup() {
    if [ "${E2E_KEEP:-0}" = "1" ]; then
        echo "Containers kept: $SHOP (http://localhost:${PORT}/${ADMIN_DIR}/, ${ADMIN_EMAIL} / ${ADMIN_PASSWD}) and $DB"
    else
        docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
        docker network rm "$NAME" >/dev/null 2>&1 || true
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

module_version() { sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" "$1/pricee/pricee.php"; }

# Module files with their autoloader, as in the release zip
build_module() {
    local dir="$1"
    docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
        -v "$dir":/build -w /build/pricee "$DEV_IMAGE" composer dump-autoload --quiet
}

deploy_module() {
    docker exec "$SHOP" rm -rf /var/www/html/modules/pricee
    docker cp "$1/pricee" "$SHOP:/var/www/html/modules/pricee"
    docker exec "$SHOP" chown -R www-data:www-data /var/www/html/modules/pricee
}

step "Starting PrestaShop ${PS_TAG} with ${DB_IMAGE} on port ${PORT}"
docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
docker network rm "$NAME" >/dev/null 2>&1 || true
docker network create "$NAME" >/dev/null
docker run -d --name "$DB" --network "$NAME" \
    -e MARIADB_DATABASE=prestashop -e MARIADB_USER=prestashop -e MARIADB_PASSWORD=prestashop -e MARIADB_ROOT_PASSWORD=root \
    "$DB_IMAGE" >/dev/null
for i in $(seq 1 60); do
    docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1 && break
    [ "$i" = 60 ] && { docker logs "$DB"; exit 1; }
    sleep 2
done

docker run -d --name "$SHOP" --network "$NAME" -p "127.0.0.1:${PORT}:80" \
    -e PS_INSTALL_AUTO=1 -e PS_DOMAIN="localhost:${PORT}" -e PS_LANGUAGE=en -e PS_COUNTRY=FR \
    -e PS_FOLDER_ADMIN="$ADMIN_DIR" -e ADMIN_MAIL="$ADMIN_EMAIL" -e ADMIN_PASSWD="$ADMIN_PASSWD" \
    -e DB_SERVER="$DB" -e DB_NAME=prestashop -e DB_USER=prestashop -e DB_PASSWD=prestashop -e DB_PREFIX=ps_ \
    -e PRICEE_API_BASE_URL=http://127.0.0.1:8090/api/v1/ \
    "prestashop/prestashop:${PS_TAG}" >/dev/null

for i in $(seq 1 240); do
    if [ "$(docker inspect -f '{{.State.Running}}' "$SHOP")" != "true" ]; then
        # Some images (e.g. 9.2) exit right after a successful install:
        # restarting skips the install and starts the web server
        if docker logs "$SHOP" 2>&1 | grep -q 'Installation successful'; then
            docker start "$SHOP" >/dev/null
        else
            docker logs "$SHOP"; exit 1
        fi
    fi
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/" || true)
    if [ "$code" -ge 200 ] 2>/dev/null && [ "$code" -lt 400 ]; then
        echo "Shop is up (HTTP $code)"
        break
    fi
    [ "$i" = 240 ] && { docker logs "$SHOP"; exit 1; }
    sleep 5
done
docker exec "$SHOP" mkdir -p /tmp/e2e
docker cp "$ROOT/e2e/shop.php" "$SHOP:/tmp/e2e/shop.php"
docker cp "$ROOT/e2e/fake-api.php" "$SHOP:/tmp/e2e/fake-api.php"
docker exec "$SHOP" chown -R www-data:www-data /tmp/e2e

step "Upgrade from ${FROM_REF} to the current code"
mkdir -p "$WORK/old" "$WORK/new"
git -C "$ROOT" archive "$FROM_REF" pricee | tar -x -C "$WORK/old"
build_module "$WORK/old"
OLD_VERSION="$(module_version "$WORK/old")"
deploy_module "$WORK/old"
# The console exits with 0 even when the install fails: the database tells
in_shop php bin/console prestashop:module install pricee || true
UPGRADE=0
if in_shop php /tmp/e2e/shop.php is-installed >/dev/null 2>&1; then
    in_shop php /tmp/e2e/shop.php configure
    UPGRADE=1
else
    echo "::warning::Release ${OLD_VERSION} cannot be installed on PrestaShop ${PS_TAG} (not compatible): the upgrade is not tested, the current code is installed instead"
fi

# The current working tree, so uncommitted changes are tested too
tar -C "$ROOT" --exclude=pricee/vendor -c pricee | tar -x -C "$WORK/new"
build_module "$WORK/new"
NEW_VERSION="$(module_version "$WORK/new")"
deploy_module "$WORK/new"
if [ "$UPGRADE" = 1 ] && [ "$OLD_VERSION" = "$NEW_VERSION" ]; then
    # PrestaShop only upgrades to a higher version: test a fresh install
    echo "::warning::Module version ${NEW_VERSION} not bumped since ${FROM_REF}: the upgrade is not tested, the module is reinstalled instead"
    in_shop php bin/console prestashop:module uninstall pricee
    UPGRADE=0
fi
if [ "$UPGRADE" = 1 ]; then
    in_shop php bin/console prestashop:module upgrade pricee
else
    in_shop php bin/console prestashop:module install pricee
    in_shop php /tmp/e2e/shop.php is-installed
    in_shop php /tmp/e2e/shop.php configure
fi
in_shop php /tmp/e2e/shop.php check-upgrade "$OLD_VERSION"

step "Webhook over HTTP"
in_shop php /tmp/e2e/shop.php webhook

step "Sync from the back office"
docker exec -d --user www-data "$SHOP" php -S 127.0.0.1:8090 /tmp/e2e/fake-api.php
for i in $(seq 1 20); do
    docker exec "$SHOP" curl -s -o /dev/null http://127.0.0.1:8090/_state && break
    [ "$i" = 20 ] && { echo "Fake Pricee API did not start"; exit 1; }
    sleep 1
done
in_shop php /tmp/e2e/shop.php sync-fixtures
MODULE_PATH="$(in_shop php /tmp/e2e/shop.php admin-url)"

# In Playwright's image (its own Node and Chromium, nothing to install on the
# host), on the host network so that localhost:<port> is the shop
mkdir -p "$ROOT/e2e/output"
docker run --rm --network host --ipc=host --user "$(id -u):$(id -g)" -e HOME=/tmp \
    -v "$ROOT/e2e:/e2e:ro" -v "$ROOT/e2e/output:/output" \
    -e SHOP_URL="http://localhost:${PORT}" -e ADMIN_DIR="$ADMIN_DIR" -e ADMIN_EMAIL="$ADMIN_EMAIL" -e ADMIN_PASSWD="$ADMIN_PASSWD" \
    -e MODULE_PATH="$MODULE_PATH" -e SYNC_CATEGORY="Pricee E2E" -e SYNC_PRODUCTS=55 \
    "$PLAYWRIGHT_IMAGE" \
    sh -c 'cp /e2e/package.json /e2e/package-lock.json /e2e/sync.mjs /tmp/ && cd /tmp && npm ci --silent --no-audit --no-fund && node sync.mjs'
in_shop php /tmp/e2e/shop.php check-sync

step "All end-to-end tests passed on PrestaShop ${PS_TAG}"
