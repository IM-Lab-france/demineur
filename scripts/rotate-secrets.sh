#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then echo "Exécutez ce script avec sudo." >&2; exit 1; fi
secure_dir="${SECURE_DIR:-/var/www/secure}"
env_file="$secure_dir/minesweeper-service.env"
[[ -r "$env_file" ]] || { echo "Configuration absente: $env_file" >&2; exit 1; }

random_suffix=$(openssl rand -hex 24)
# Garantit les quatre classes généralement exigées par validate_password :
# majuscule, minuscule, chiffre et caractère spécial.
new_password="Aa1!${random_suffix}"
[[ ${#new_password} -eq 52 ]] || { echo "Génération du secret impossible." >&2; exit 1; }
set -a
# shellcheck disable=SC1090
source "$env_file"
set +a
: "${DB_USER:?}" "${DB_PASS:?}"

escaped=${new_password//\\/\\\\}; escaped=${escaped//\'/\'\'}
mapfile -t account_hosts < <(mysql -N -B -e "SELECT Host FROM mysql.user WHERE User='${DB_USER//\'/\'\'}'")
[[ ${#account_hosts[@]} -gt 0 ]] || { echo "Compte MySQL introuvable." >&2; exit 1; }
for account_host in "${account_hosts[@]}"; do
  [[ "$account_host" =~ ^[A-Za-z0-9._%:-]+$ ]] || { echo "Hôte MySQL inattendu." >&2; exit 1; }
  mysql -e "ALTER USER '$DB_USER'@'$account_host' IDENTIFIED BY '$escaped';"
done
tmp=$(mktemp); trap 'rm -f "$tmp"' EXIT
awk '!/^DB_PASS=/' "$env_file" > "$tmp"
printf 'DB_PASS=%s\n' "$new_password" >> "$tmp"
install -o root -g minesweeper -m 0640 "$tmp" "$secure_dir/minesweeper-service.env"
install -o root -g minesweeper -m 0640 "$tmp" "$secure_dir/.env"
systemctl restart minesweeper-websocket.service
echo "Secret MySQL renouvelé. Révoquez séparément toute clé SSH exposée avant de supprimer la quarantaine."
