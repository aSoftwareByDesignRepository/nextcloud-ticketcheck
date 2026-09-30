#!/usr/bin/env bash
# Prove companion Basic-auth flood is throttled by NC bruteforce.
# Contract: wrong password → 401; after max delay → 429 (JSON when Accept is not HTML).
# Do NOT use HTML /login form POST as the proof (redirect redisplay is often 200).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NC_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$NC_ROOT/docker-compose.yml}"
API="${TICKETCHECK_API:-http://127.0.0.1:8081/index.php/apps/ticketcheck/companion/api/v1}"
USER="${TICKETCHECK_E2E_USER:-tkcagent}"
WRONG_PASS="${TICKETCHECK_WRONG_PASS:-DefinitelyWrongPassword!!!}"
ATTEMPTS="${TICKETCHECK_THROTTLE_ATTEMPTS:-25}"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

bash "$SCRIPT_DIR/ensure-auth-bruteforce.sh"

# Clear prior attempts so this run is deterministic
docker compose -f "$COMPOSE_FILE" exec -T nextcloud php occ security:bruteforce:reset 127.0.0.1 >/dev/null 2>&1 || true
docker compose -f "$COMPOSE_FILE" exec -T nextcloud php occ security:bruteforce:reset ::1 >/dev/null 2>&1 || true
docker compose -f "$COMPOSE_FILE" exec -T mariadb bash -lc \
  "mysql -unextcloud -pnextcloud_password nextcloud -e 'DELETE FROM oc_bruteforce_attempts;'" >/dev/null 2>&1 || true

log "Flooding $API/bootstrap as $USER (wrong password ×$ATTEMPTS)"
codes=()
saw401=0
saw429=0
for i in $(seq 1 "$ATTEMPTS"); do
  code="$(curl -s -o /tmp/tkc-throttle-body.json -w '%{http_code}' \
    -u "${USER}:${WRONG_PASS}" \
    -H 'Accept: application/json' \
    -H 'OCS-APIRequest: true' \
    "${API}/bootstrap" || echo ERR)"
  codes+=("$code")
  [[ "$code" == "401" ]] && saw401=1
  [[ "$code" == "429" ]] && saw429=1
  printf '  attempt %02d → %s\n' "$i" "$code"
  # Stop early once 429 proven
  if [[ "$saw429" -eq 1 ]]; then
    break
  fi
done

printf 'CODES %s\n' "${codes[*]}"
[[ "$saw401" -eq 1 ]] || die "expected at least one 401 before throttle"
[[ "$saw429" -eq 1 ]] || die "expected 429 RATE_LIMITED after flood (got: ${codes[*]})"
# Body should be JSON-ish (NC or companion envelope)
head -c 200 /tmp/tkc-throttle-body.json; echo
log "PASS companion auth throttle (401 then 429)"
