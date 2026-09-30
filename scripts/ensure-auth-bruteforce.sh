#!/usr/bin/env bash
# Security baseline for the shared Docker Nextcloud lab.
# Companion Basic-auth throttling is Nextcloud core bruteforce (action: login).
# Agents sometimes disable it for speed — that is NOT acceptable for audit proof.
#
# Usage (from host):
#   bash apps/ticketcheck/scripts/ensure-auth-bruteforce.sh
#   COMPOSE_FILE=/path/to/docker-compose.yml bash apps/ticketcheck/scripts/ensure-auth-bruteforce.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NC_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$NC_ROOT/docker-compose.yml}"
MAX_ATTEMPTS="${TICKETCHECK_BRUTEFORCE_MAX_ATTEMPTS:-10}"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ -f "$COMPOSE_FILE" ]] || die "compose not found: $COMPOSE_FILE"

log "Enabling NC auth bruteforce (max-attempts=$MAX_ATTEMPTS)"
docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=true
# Canonical key uses a hyphen (Throttler reads max-attempts). Drop the bogus underscore key if present.
docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:set auth.bruteforce.max-attempts --type=integer --value="$MAX_ATTEMPTS"
docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:delete auth.bruteforce.max_attempts >/dev/null 2>&1 || true
docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:set auth.bruteforce.protection.testing --type=boolean --value=false

enabled="$(docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:get auth.bruteforce.protection.enabled | tr -d '\r')"
max="$(docker compose -f "$COMPOSE_FILE" exec -T nextcloud \
  php occ config:system:get auth.bruteforce.max-attempts | tr -d '\r')"

[[ "$enabled" == "1" || "$enabled" == "true" ]] || die "bruteforce still disabled (got: $enabled)"
log "OK enabled=$enabled max-attempts=$max"
