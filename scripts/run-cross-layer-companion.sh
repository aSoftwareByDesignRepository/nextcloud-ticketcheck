#!/usr/bin/env bash
# True cross-layer E2E: mobile public reply → companion API → MariaDB.
# Shared RUN_ID is embedded in the comment body so both layers check the same row.
#
# Prereq: InventoryCheck locked + agent logged in (or run login-dev first).
#   export ANDROID_SERIAL=emulator-XXXX
#   bash scripts/run-cross-layer-companion.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NC_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"          # .../nextcloud
DEV_ROOT="$(cd "$NC_ROOT/.." && pwd)"                  # .../nextcloud-dev
MOBILE="${TICKETCHECK_MOBILE:-$DEV_ROOT/mobile/ticketcheck}"
COMPOSE_FILE="${COMPOSE_FILE:-$NC_ROOT/docker-compose.yml}"
API="${TICKETCHECK_API:-http://localhost:8081/index.php/apps/ticketcheck/companion/api/v1}"
RUN_ID="${RUN_ID:-atlas-$(date +%s)-$RANDOM}"
E2E_USER="${TICKETCHECK_E2E_USER:-tkcagent}"
E2E_PASSWORD="${TICKETCHECK_E2E_PASSWORD:-TkC_Agent_E2e_2026!}"
DEVICE="${TICKETCHECK_MAESTRO_DEVICE:-${ANDROID_SERIAL:-}}"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ -f "$COMPOSE_FILE" ]] || die "compose not found: $COMPOSE_FILE"
[[ -d "$MOBILE" ]] || die "mobile app not found: $MOBILE"
command -v maestro >/dev/null || die "maestro CLI missing"
[[ -n "$DEVICE" ]] || die "Set TICKETCHECK_MAESTRO_DEVICE / ANDROID_SERIAL"

mint_app_password() {
  export OC_PASS="$E2E_PASSWORD"
  docker compose -f "$COMPOSE_FILE" exec -T -e OC_PASS nextcloud \
    php occ user:resetpassword --password-from-env "$E2E_USER" >/dev/null
  docker compose -f "$COMPOSE_FILE" exec -T -e OC_PASS nextcloud bash -lc \
    "php occ user:auth-tokens:add -n --password-from-env --name=xlayer-${RUN_ID} ${E2E_USER} 2>&1" \
    | sed -n '/app password:/{n;p;}' | tr -d '\r '
}

log "RUN_ID=$RUN_ID device=$DEVICE"

BODY="Atlas cross-layer ${RUN_ID}"
FLOW="$(mktemp /tmp/companion-smoke-runid.XXXXXX.yaml)"
trap 'rm -f "$FLOW"' EXIT
cat >"$FLOW" <<YAML
appId: de.softwarebydesign.ticketcheck
---
- launchApp:
    stopApp: true
- extendedWaitUntil:
    visible: "Queues|Connect|Unable to load script"
    timeout: 180000
- runFlow:
    when:
      visible: "Unable to load script"
    commands:
      - tapOn: "RELOAD"
      - extendedWaitUntil:
          visible: "Queues|Connect"
          timeout: 180000
- assertVisible: "Queues"
- tapOn:
    id: "home.queue.open"
- extendedWaitUntil:
    visible: ".*(TK-|HD-).*"
    timeout: 60000
- tapOn:
    text: ".*(TK-|HD-).*"
- extendedWaitUntil:
    visible: "ticket.detail|Change status|Add attachment"
    timeout: 30000
- scrollUntilVisible:
    element: "Reply"
    direction: DOWN
    timeout: 30000
- scrollUntilVisible:
    element:
      id: "ticket.replyInput"
    direction: DOWN
    timeout: 30000
- tapOn: "Public reply"
- tapOn:
    id: "ticket.replyInput"
- eraseText
- inputText: "${BODY}"
- hideKeyboard
- scrollUntilVisible:
    element:
      id: "ticket.send"
    direction: DOWN
    timeout: 20000
- tapOn:
    id: "ticket.send"
- extendedWaitUntil:
    visible: "Reply sent.|${BODY}|Someone else changed this ticket"
    timeout: 90000
- assertVisible: "Reply sent.|${BODY}"
- takeScreenshot: cross-layer-reply
YAML

log "Maestro trigger"
maestro --device "$DEVICE" test "$FLOW"

AGENT_PASS="$(mint_app_password)"
[[ -n "$AGENT_PASS" ]] || die "failed to mint app password"

FOUND=0
TICKET_ID=""
for _ in $(seq 1 30); do
  mapfile -t IDS < <(curl -sS -u "${E2E_USER}:${AGENT_PASS}" "$API/inbox?queue=open" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print("\n".join(str(x["id"]) for x in (d.get("items") or [])))' || true)
  for tid in "${IDS[@]:-}"; do
    [[ -z "${tid:-}" ]] && continue
    if curl -sS -u "${E2E_USER}:${AGENT_PASS}" "$API/tickets/$tid" \
      | python3 -c "import json,sys; d=json.load(sys.stdin); bodies=[c.get('body','') for c in d.get('comments') or []]; sys.exit(0 if any('$RUN_ID' in b for b in bodies) else 1)"; then
      FOUND=1
      TICKET_ID="$tid"
      break 2
    fi
  done
  sleep 1
done
[[ "$FOUND" -eq 1 ]] || die "API never showed comment with RUN_ID=$RUN_ID"
log "API OK ticket=$TICKET_ID"

COUNT="$(docker compose -f "$COMPOSE_FILE" exec -T mariadb \
  bash -lc "mysql -unextcloud -pnextcloud_password nextcloud -N -e \"SELECT COUNT(*) FROM oc_helpdesk_comments WHERE content LIKE '%${RUN_ID}%';\"" \
  | tr -d '\r' | tail -1)"
[[ "${COUNT:-0}" -ge 1 ]] || die "DB has no row for RUN_ID=$RUN_ID"
log "DB OK rows=$COUNT"

OUT_DIR="$MOBILE/screenshots/android"
mkdir -p "$OUT_DIR"
printf '%s\n' "{\"runId\":\"$RUN_ID\",\"ticketId\":$TICKET_ID,\"dbRows\":$COUNT,\"body\":\"$BODY\"}" \
  >"$OUT_DIR/cross-layer-manifest-${RUN_ID}.json"
log "PASS cross-layer RUN_ID=$RUN_ID ticket=$TICKET_ID manifest=$OUT_DIR/cross-layer-manifest-${RUN_ID}.json"
