#!/usr/bin/env bash
set -euo pipefail

echo "[ticketcheck:e2e] Safe mode enabled"
echo "[ticketcheck:e2e] No docker compose changes will be made."
echo "[ticketcheck:e2e] No rebuild/restart/down commands are executed."

if [[ ! -f "playwright.config.js" ]]; then
  echo "[ticketcheck:e2e] playwright config missing. Run from apps/ticketcheck."
  exit 1
fi

# Auto-load local e2e/.env when present (gitignored) — before credential checks.
if [[ -f "e2e/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "e2e/.env"
  set +a
  echo "[ticketcheck:e2e] Loaded e2e/.env"
fi

if [[ -z "${E2E_AGENT_CREATE_TICKET_URL:-}" ]]; then
  echo "[ticketcheck:e2e] E2E_AGENT_CREATE_TICKET_URL not set (agent create test will be skipped)."
fi

if [[ -z "${E2E_GUEST_CREATE_TICKET_URL:-}" ]]; then
  echo "[ticketcheck:e2e] E2E_GUEST_CREATE_TICKET_URL not set (guest attachment test will be skipped)."
fi

if [[ -z "${E2E_AGENT_TICKET_DETAIL_URL:-}" ]]; then
  echo "[ticketcheck:e2e] E2E_AGENT_TICKET_DETAIL_URL not set (status recovery test will be skipped)."
fi

if [[ -z "${E2E_AGENT_TICKETS_LIST_URL:-}" ]]; then
  echo "[ticketcheck:e2e] E2E_AGENT_TICKETS_LIST_URL not set (tickets list a11y test will be skipped)."
fi

if [[ -z "${E2E_STORAGE_STATE:-}" ]]; then
  if [[ -n "${E2E_USER:-}" && -n "${E2E_PASSWORD:-${E2E_PASS:-}}" ]]; then
    echo "[ticketcheck:e2e] E2E_USER set — global-setup will try to write .auth/storage-state.json"
  else
    echo "[ticketcheck:e2e] E2E_STORAGE_STATE not set (agent tests skip unless E2E_USER + E2E_PASSWORD)"
  fi
fi

if [[ -z "${E2E_GUEST_STORAGE_STATE:-}" ]]; then
  if [[ -n "${E2E_GUEST_USER:-}" && -n "${E2E_GUEST_PASSWORD:-}" ]]; then
    echo "[ticketcheck:e2e] E2E_GUEST_USER set — global-setup will try to write .auth/guest-storage-state.json"
  else
    echo "[ticketcheck:e2e] Guest credentials not set (portal smoke will skip on login page)"
  fi
fi

# Atlas/CI gate: E2E_REQUIRE_AUTH=1 turns every auth-skip into a hard failure so a
# misconfigured run can never report green with zero authenticated coverage.
if [[ "${E2E_REQUIRE_AUTH:-}" =~ ^(1|true|yes)$ ]]; then
  echo "[ticketcheck:e2e] E2E_REQUIRE_AUTH=1 — unauthenticated tests will FAIL, not skip"
fi

# Sibling app version bumps flip needsDbUpgrade and block authenticated pages behind
# the "Update needed" interstitial — clear that before smoke (safe: upgrade only).
if command -v docker >/dev/null 2>&1; then
  COMPOSE_DIR="$(cd ../.. && pwd)"
  if (cd "$COMPOSE_DIR" && docker compose ps nextcloud 2>/dev/null | grep -q 'Up'); then
    if (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ status 2>/dev/null | grep -q 'needsDbUpgrade: true'); then
      echo "[ticketcheck:e2e] Running occ upgrade (needsDbUpgrade)..."
      (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ upgrade)
      (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ maintenance:mode --off || true)
    fi
  fi
fi

echo "[ticketcheck:e2e] Running Playwright smoke tests..."
rm -f .auth/storage-state.json .auth/guest-storage-state.json
npx playwright test e2e
exit $?
