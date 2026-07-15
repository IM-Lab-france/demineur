#!/usr/bin/env bash
set -euo pipefail

status_dir="${STATUS_DIR:-/var/log/minesweeper}"
issues=()
for unit in minesweeper-websocket.service minesweeper-backup.timer minesweeper-backup-verify.timer; do
  systemctl is-active --quiet "$unit" || issues+=("$unit inactif")
done

check_age() {
  local file=$1 max_age=$2 label=$3 completed epoch age
  [[ -r "$file" ]] || { issues+=("$label absent"); return; }
  completed=$(sed -n 's/.*"completedAt":"\([^"]*\)".*/\1/p' "$file")
  epoch=$(date -d "$completed" +%s 2>/dev/null || echo 0)
  age=$(( $(date +%s) - epoch ))
  (( epoch > 0 && age <= max_age )) || issues+=("$label trop ancien")
}

check_age "$status_dir/backup-status.json" 129600 "sauvegarde"
check_age "$status_dir/restore-status.json" 691200 "test de restauration"

mkdir -p "$status_dir"
if (( ${#issues[@]} == 0 )); then
  printf '{"completedAt":"%s","status":"success","message":"Tous les contrôles sont valides"}\n' "$(date -u +%FT%TZ)" > "$status_dir/health-status.json"
else
  message=$(IFS='; '; echo "${issues[*]}")
  escaped=${message//\\/\\\\}; escaped=${escaped//\"/\\\"}
  printf '{"completedAt":"%s","status":"error","message":"%s"}\n' "$(date -u +%FT%TZ)" "$escaped" > "$status_dir/health-status.json"
  logger -p daemon.err -t minesweeper-health -- "$message"
fi
chown root:minesweeper "$status_dir/health-status.json"
chmod 0640 "$status_dir/health-status.json"
(( ${#issues[@]} == 0 ))
