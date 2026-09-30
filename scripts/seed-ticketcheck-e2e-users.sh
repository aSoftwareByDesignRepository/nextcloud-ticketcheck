#!/usr/bin/env bash
# Seed TicketCheck Atlas / Playwright E2E lab users (idempotent).
# Runs inside the nextcloud container via:
#   docker compose exec -T nextcloud bash /path/or/stdin
set -euo pipefail

# Shared lab often has bruteforce disabled for speed — restore production defaults
# so companion Basic-auth throttle proofs stay valid after seeding.
php occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=true >/dev/null
php occ config:system:set auth.bruteforce.max-attempts --type=integer --value=10 >/dev/null
php occ config:system:delete auth.bruteforce.max_attempts >/dev/null 2>&1 || true
php occ config:system:set auth.bruteforce.protection.testing --type=boolean --value=false >/dev/null

ensure_user() {
  local uid="$1" display="$2" pass="$3"
  export OC_PASS="$pass"
  if ! php occ user:info "$uid" >/dev/null 2>&1; then
    php occ user:add --password-from-env --display-name="$display" "$uid" || true
  fi
  php occ user:resetpassword --password-from-env "$uid"
}

ensure_group() {
  local g="$1"
  php occ group:add "$g" >/dev/null 2>&1 || true
}

add_to() {
  php occ group:adduser "$1" "$2" >/dev/null 2>&1 || true
}

ensure_group helpdesk_agents
ensure_group helpdesk_admins
ensure_group helpdesk_customers

ensure_user tkcagent 'TKC Agent' 'TkC_Agent_E2e_2026!'
add_to helpdesk_agents tkcagent

ensure_user tkcnoseat 'No Seat Agent' 'TkC_Noseat_E2e_2026!'
add_to helpdesk_agents tkcnoseat

ensure_user tkchdadmin 'TKC HD Admin' 'TkC_HdAdmin_E2e_2026!'
add_to helpdesk_admins tkchdadmin
add_to helpdesk_agents tkchdadmin

ensure_user tkcguest 'TKC Guest' 'TkC_Guest_E2e_2026!'
add_to helpdesk_customers tkcguest

ensure_user e2e_guest 'E2E Guest' 'TkC_Guest_E2e_2026!'
add_to helpdesk_customers e2e_guest

# Guest-wins dual-role (companion must ROLE_DENIED)
ensure_user tkcdual 'TKC Dual Role' 'TkC_Dual_E2e_2026!'
add_to helpdesk_customers tkcdual
add_to helpdesk_agents tkcdual

echo "seed-ticketcheck-e2e-users: ok"
php occ user:info tkcdual | sed -n '1,12p'
