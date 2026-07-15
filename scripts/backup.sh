#!/usr/bin/env bash
set -euo pipefail

umask 077
backup_root="${BACKUP_DIR:-/var/backups/minesweeper}"
retention_days="${BACKUP_RETENTION_DAYS:-14}"
secure_dir="${SECURE_DIR:-/var/www/secure}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="$backup_root/$timestamp"

mkdir -p "$destination"

env_file="$secure_dir/.env"
[[ -f "$env_file" ]] || env_file="$secure_dir/minesweeper-service.env"
if [[ -f "$env_file" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$env_file"
  set +a
fi

: "${DB_NAME:?DB_NAME doit être défini}"
: "${DB_USER:?DB_USER doit être défini}"
: "${DB_PASS:?DB_PASS doit être défini}"

MYSQL_PWD="$DB_PASS" mysqldump \
  --single-transaction --quick --skip-lock-tables \
  -h "${DB_HOST:-127.0.0.1}" -u "$DB_USER" "$DB_NAME" \
  | gzip -9 > "$destination/database.sql.gz"

if [[ -d "$secure_dir" ]]; then
  tar -C "$(dirname "$secure_dir")" -czf "$destination/secure-config.tar.gz" "$(basename "$secure_dir")"
fi

sha256sum "$destination"/* > "$destination/SHA256SUMS"
find "$backup_root" -mindepth 1 -maxdepth 1 -type d -mtime "+$retention_days" -exec rm -rf -- {} +
echo "Sauvegarde créée dans $destination"
