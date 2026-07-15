#!/usr/bin/env bash
set -euo pipefail

umask 077
backup_root="${BACKUP_DIR:-/var/backups/minesweeper}"
secure_dir="${SECURE_DIR:-/var/www/secure}"
status_dir="${STATUS_DIR:-/var/log/minesweeper}"
archive="${1:-$(find "$backup_root" -mindepth 2 -maxdepth 2 -name database.sql.gz -type f -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)}"

[[ -n "$archive" && -r "$archive" ]] || { echo "Aucune sauvegarde lisible." >&2; exit 1; }
env_file="$secure_dir/.env"
[[ -f "$env_file" ]] || env_file="$secure_dir/minesweeper-service.env"
[[ -r "$env_file" ]] || { echo "Configuration sécurisée introuvable." >&2; exit 1; }

set -a
# shellcheck disable=SC1090
source "$env_file"
set +a
: "${DB_HOST:?}" "${DB_USER:?}" "${DB_PASS:?}"

restore_db="demineur_restore_check_$(date +%s)"
if [[ ${EUID:-$(id -u)} -eq 0 && ( "$DB_HOST" == "localhost" || "$DB_HOST" == "127.0.0.1" ) ]]; then
  mysql_cmd=(mysql)
else
  mysql_cmd=(env "MYSQL_PWD=$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER")
fi
cleanup() { "${mysql_cmd[@]}" -e "DROP DATABASE IF EXISTS \`$restore_db\`" >/dev/null 2>&1 || true; }
trap cleanup EXIT

"${mysql_cmd[@]}" -e "CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -cd -- "$archive" | "${mysql_cmd[@]}" "$restore_db"
table_count=$("${mysql_cmd[@]}" -N -B "$restore_db" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")
for table in users game_details game_moves invitations; do
  "${mysql_cmd[@]}" -N -B "$restore_db" -e "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$table'" | grep -qx 1
done
echo "Restauration vérifiée : $table_count table(s), archive $archive"
printf '{"completedAt":"%s","status":"success","tables":%s}\n' "$(date -u +%FT%TZ)" "$table_count" > "$status_dir/restore-status.json"
chmod 0640 "$status_dir/restore-status.json"
