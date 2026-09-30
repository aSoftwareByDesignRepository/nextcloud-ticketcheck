#!/usr/bin/env bash
# TicketCheck — cross-layer proof (companion API → MariaDB), shared run ID.
#
# Usage (from nextcloud/ compose root or anywhere):
#   AGENT_PASS=… bash apps/ticketcheck/scripts/run-cross-layer.sh
#   # or with tokens already minted:
#   AGENT_PASS=$(cat /tmp/atlas-tkc-r3/tkcagent.token) bash …/run-cross-layer.sh
#
# Env:
#   NC_BASE          default http://localhost:8081
#   COMPOSE_DIR      default <repo>/nextcloud (directory with docker-compose.yml)
#   AGENT_USER       default tkcagent
#   AGENT_PASS       required (app password)
#   TICKET_ID        default 5
#   RUN_ID           default atlas-cross-<utc>
set -euo pipefail

NC_BASE="${NC_BASE:-http://localhost:8081}"
API="${NC_BASE%/}/index.php/apps/ticketcheck/companion/api/v1"
AGENT_USER="${AGENT_USER:-tkcagent}"
TICKET_ID="${TICKET_ID:-5}"
RUN_ID="${RUN_ID:-atlas-cross-$(date -u +%Y%m%dT%H%M%SZ)}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE_DIR="${COMPOSE_DIR:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
# Prefer apps/ticketcheck → nextcloud root
if [[ ! -f "$COMPOSE_DIR/docker-compose.yml" ]]; then
  COMPOSE_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"
fi

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ -n "${AGENT_PASS:-}" ]] || die "AGENT_PASS (app password) required"
[[ -f "$COMPOSE_DIR/docker-compose.yml" ]] || die "docker-compose.yml not found near $COMPOSE_DIR"

MARKER="CROSS_LAYER ${RUN_ID} please ignore"
OUT_DIR="${OUT_DIR:-/tmp/ticketcheck-cross-layer/$RUN_ID}"
mkdir -p "$OUT_DIR"

auth=(-u "${AGENT_USER}:${AGENT_PASS}")

echo "==> run_id=$RUN_ID ticket=$TICKET_ID"
VER=$(curl -sS --max-time 30 "${auth[@]}" "$API/tickets/$TICKET_ID" | python3 -c 'import json,sys;print(json.load(sys.stdin)["ticket"]["version"])')
echo "==> pre_version=$VER"

curl -sS --max-time 30 -o "$OUT_DIR/comment.json" -w "comment_http=%{http_code}\n" \
  -X POST "${auth[@]}" -H 'Content-Type: application/json' \
  -d "{\"body\":$(python3 -c "import json;print(json.dumps('$MARKER'))"),\"visibility\":\"public\",\"version\":$VER}" \
  "$API/tickets/$TICKET_ID/comments"

python3 - <<PY
import json,sys
d=json.load(open("$OUT_DIR/comment.json"))
if not d.get("ok"):
  print("API comment failed:", d, file=sys.stderr); sys.exit(1)
print("api_comment_ok version=", (d.get("ticket") or {}).get("version"))
open("$OUT_DIR/api_ok","w").write("1")
PY

# DB ground truth via MariaDB service
SQL="SELECT id, ticket_id, LEFT(content, 120) AS body, is_internal, created_at
FROM oc_helpdesk_comments
WHERE ticket_id = ${TICKET_ID} AND content LIKE '%${RUN_ID}%'
ORDER BY id DESC LIMIT 5;"

docker compose -f "$COMPOSE_DIR/docker-compose.yml" exec -T mariadb \
  mysql -unextcloud -pnextcloud_password nextcloud -e "$SQL" \
  | tee "$OUT_DIR/db.tsv"

python3 - <<PY
import pathlib,sys
text=pathlib.Path("$OUT_DIR/db.tsv").read_text()
if "$RUN_ID" not in text:
  print("DB missing RUN_ID marker", file=sys.stderr); sys.exit(1)
print("db_ok rows_with_marker_present")
print("CROSS_LAYER_OK", "$RUN_ID")
PY
