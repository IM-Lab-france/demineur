#!/usr/bin/env bash
set -euo pipefail

[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo "Ce script doit être exécuté par root." >&2; exit 1; }
backup_id=${1:-}
[[ "$backup_id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || { echo "Identifiant de sauvegarde invalide." >&2; exit 2; }

backup_root="${BACKUP_DIR:-/var/backups/minesweeper}"
secure_dir="${SECURE_DIR:-/var/www/secure}"
status_dir="${STATUS_DIR:-/var/log/minesweeper}"
directory="$backup_root/$backup_id"
archive="$directory/database.sql.gz"
admin_auth_state="/run/minesweeper-admin-auth.json"
[[ -r "$archive" && -r "$directory/SHA256SUMS" ]] || { echo "Sauvegarde introuvable ou incomplète." >&2; exit 2; }
(cd "$directory" && sha256sum -c SHA256SUMS)

env_file="$secure_dir/.env"
[[ -f "$env_file" ]] || env_file="$secure_dir/minesweeper-service.env"
set -a
# shellcheck disable=SC1090
source "$env_file"
set +a
: "${DB_NAME:?}" "${DB_HOST:?}" "${DB_USER:?}" "${DB_PASS:?}"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Nom de base invalide." >&2; exit 2; }

restart_websocket=false
if systemctl is-active --quiet minesweeper-websocket.service; then restart_websocket=true; fi
mapfile -t active_ai < <(systemctl list-units 'minesweeper-ai@*.service' --state=active --no-legend --plain | awk '{print $1}')
restart_services() {
  if $restart_websocket; then systemctl start minesweeper-websocket.service || true; fi
  for unit in "${active_ai[@]}"; do systemctl start "$unit" || true; done
}
cleanup_runtime() { rm -f "$admin_auth_state"; restart_services; }
trap cleanup_runtime EXIT

for unit in "${active_ai[@]}"; do systemctl stop "$unit"; done
systemctl stop minesweeper-websocket.service

# Point de retour obligatoire immédiatement avant toute modification destructive.
/var/www/demineur/scripts/backup.sh
/usr/bin/php /var/www/demineur/scripts/reconcile-restored-accounts.php export-admins "$admin_auth_state"

stage_db="${DB_NAME}_restore_stage_$(date +%s)"
if [[ "$DB_HOST" == localhost || "$DB_HOST" == 127.0.0.1 ]]; then
  mysql_cmd=(mysql)
else
  mysql_cmd=(env "MYSQL_PWD=$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER")
fi
cleanup_stage() { "${mysql_cmd[@]}" -e "DROP DATABASE IF EXISTS \`$stage_db\`" >/dev/null 2>&1 || true; }
trap 'cleanup_stage; cleanup_runtime' EXIT

"${mysql_cmd[@]}" -e "CREATE DATABASE \`$stage_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -cd -- "$archive" | "${mysql_cmd[@]}" "$stage_db"
for table in users game_details game_moves invitations account_tokens auth_sessions email_outbox active_games; do
  "${mysql_cmd[@]}" -N -B "$stage_db" -e "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$table'" | grep -qx 1
done

"${mysql_cmd[@]}" -e "DROP DATABASE \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -cd -- "$archive" | "${mysql_cmd[@]}" "$DB_NAME"
/usr/bin/php /var/www/demineur/scripts/reconcile-restored-accounts.php reconcile "$admin_auth_state"
cleanup_stage
trap cleanup_runtime EXIT
restart_services
if $restart_websocket && ! systemctl is-active --quiet minesweeper-websocket.service; then
  echo "Le serveur WebSocket n’a pas redémarré." >&2
  exit 1
fi
for unit in "${active_ai[@]}"; do
  systemctl is-active --quiet "$unit" || { echo "Le service $unit n’a pas redémarré." >&2; exit 1; }
done
restart_websocket=false; active_ai=(); rm -f "$admin_auth_state"
systemctl start minesweeper-health.service || true
printf '{"completedAt":"%s","status":"success","backupId":"%s"}\n' "$(date -u +%FT%TZ)" "$backup_id" > "$status_dir/restore-production-status.json"
chown root:minesweeper "$status_dir/restore-production-status.json"
chmod 0640 "$status_dir/restore-production-status.json"
echo "Base $DB_NAME restaurée depuis $backup_id."
