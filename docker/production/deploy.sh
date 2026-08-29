#!/usr/bin/env bash
#
# TASK-MVP-017 (RISK_REGISTER.md R76, DECISION_LOG.md C92). Canonical
# production deployment step - everything AFTER the host source tree has
# already been updated to the exact commit given as $1 (R65/C76's own
# "host source tree is the deployment's single source of truth" rule -
# unchanged, still a manual tar+scp+sha256-verify step performed BEFORE
# this script runs, never done by this script itself).
#
# Exists specifically to close a real, live incident: every prior
# deployment in this project's history rebuilt/recreated `app` (PHP-FPM)
# alone, via commands typed by hand - `web` (nginx, the container that
# actually SERVES /themes/.../build/... static assets, built via a
# build-time `COPY --from=app` in Dockerfile.production, never a shared
# runtime volume with `app`) went 10 days without being rebuilt, silently
# serving stale JS/CSS until the Admin theme's Vite manifest referenced a
# file `web`'s own stale image never had at all - breaking Vue app-mount
# or every Merchant Admin tenant. No documentation ever explicitly wrote
# "app only" as guidance; the omission of `web` was simply never written
# down as a complete, precise command anywhere - this script is that
# precise command now, replacing operator memory/habit with one canonical,
# hard-to-abbreviate path.
#
# Usage:
#   docker/production/deploy.sh <git-commit-sha>
#
# Deliberately does NOT:
#   - sync/update the host source tree (assumed already done, per R65/C76)
#   - roll back on failure (diagnosis/recovery stays a deliberate,
#     separate operator decision - see the FINAL GATE section below)
#   - touch mysql/redis in any way (see --no-deps below)
#   - mutate any tenant data
#   - require or read a real merchant/operator password

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <git-commit-sha>" >&2
    exit 1
fi

COMMIT="$1"

if [[ ! "${COMMIT}" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
    echo "Error: '${COMMIT}' does not look like a git commit SHA (expected 7-40 hex characters)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${APP_ROOT}/docker-compose.production.yml"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
    echo "Error: ${COMPOSE_FILE} not found - is this script running from the real deployed host source tree?" >&2
    exit 1
fi

cd "${APP_ROOT}"

echo "==> Deploying commit ${COMMIT}"
echo "==> Host source tree: ${APP_ROOT}"
echo "==> (Assumes the host source tree above was already updated to this exact commit via the standard tar+scp+sha256-verify process - this script does not sync source itself.)"
echo

echo "==> Writing APP_COMMIT marker"
echo "${COMMIT}" > "${APP_ROOT}/APP_COMMIT"

echo "==> Building app + web together (never one alone - see RISK_REGISTER.md R76)"
docker compose -f "${COMPOSE_FILE}" build app web

# --no-deps: explicitly, deterministically limits this command to ONLY the
# two services named - mysql/redis are declared as `depends_on` for `app`/
# `web` in docker-compose.production.yml, and WITHOUT --no-deps, `up`
# could start (though not recreate) a dependency that happened not to be
# running yet. --no-deps removes that ambiguity entirely: mysql/redis are
# never started, recreated, or otherwise touched by this command,
# regardless of their current state. Verified directly against Docker
# Compose's own documented flag semantics ("Don't start linked services")
# and, separately, against this exact production compose file during this
# project's own real incident recovery (`up -d --force-recreate --no-deps
# web` left app/mysql/redis's own uptimes completely unchanged).
echo "==> Recreating app + web together (mysql/redis are never touched - see --no-deps above)"
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate --no-deps app web

echo "==> Clearing application caches"
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan config:clear
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan route:clear
docker compose -f "${COMPOSE_FILE}" exec -T app php artisan view:clear

# FINAL GATE, explicit product-owner decision: platform:production:check
# is the deployment's own final verification. A non-zero exit here means
# this script ALSO exits non-zero and reports failure clearly - a failed
# production check is never silently treated as a successful deployment.
# Deliberately NO automatic rollback/restore here: the application source
# and images have already been updated by this point (that part cannot be
# silently undone without risking a worse, half-reverted state) - a real
# failure here needs a human to look at the actual `platform:production:
# check` output above and decide the right next step, not a script
# guessing at one.
echo "==> Running platform:production:check (final deployment gate)"
echo
if ! docker compose -f "${COMPOSE_FILE}" exec -T app php artisan platform:production:check; then
    echo
    echo "==> DEPLOYMENT FAILED." >&2
    echo "==> The application source/images/containers have already been updated to commit ${COMMIT}," >&2
    echo "==> but platform:production:check did NOT pass - production readiness is NOT confirmed." >&2
    echo "==> This script does not roll back automatically. Review the check output above," >&2
    echo "==> diagnose the specific failing row, and decide the correct recovery step manually." >&2
    exit 1
fi

echo
echo "==> Deployment of ${COMMIT} succeeded and passed production readiness verification."
