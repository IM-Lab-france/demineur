#!/usr/bin/env bash
set -euo pipefail

status_dir="${STATUS_DIR:-/var/log/minesweeper}"
issues=()
env_file="${SECURE_DIR:-/var/www/secure}/minesweeper-service.env"
if [[ -r "$env_file" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$env_file"
  set +a
fi
[[ -n "${MAILER_DSN:-}" && -n "${MAIL_FROM_ADDRESS:-}" && -n "${APP_PUBLIC_URL:-}" ]] || issues+=("SMTP non configuré")
if [[ -n "${DB_HOST:-}" && -n "${DB_USER:-}" && -n "${DB_PASS:-}" ]]; then
  failed_mails=$(MYSQL_PWD="$DB_PASS" mysql -N -B -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" -e "SELECT IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='email_outbox'),(SELECT COUNT(*) FROM email_outbox WHERE sent_at IS NULL AND attempts>=10),0)" 2>/dev/null || echo 0)
  [[ "$failed_mails" =~ ^[0-9]+$ && "$failed_mails" -eq 0 ]] || issues+=("e-mails en échec")
fi
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
