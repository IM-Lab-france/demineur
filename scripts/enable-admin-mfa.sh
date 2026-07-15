#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then echo "Exécutez avec sudo." >&2; exit 1; fi
read -r -p "Nom du compte administrateur : " admin_user
[[ "$admin_user" =~ ^[A-Za-z0-9_-]{3,32}$ ]] || { echo "Compte invalide." >&2; exit 1; }
secret=$(openssl rand 20 | base32 | tr -d '=\n')

for env_file in /var/www/secure/.env /var/www/secure/minesweeper-service.env; do
  [[ -f "$env_file" ]] || continue
  temp=$(mktemp)
  awk '!/^ADMIN_TOTP_SECRET=/' "$env_file" > "$temp"
  printf 'ADMIN_TOTP_SECRET=%s\n' "$secret" >> "$temp"
  install -o root -g minesweeper -m 0640 "$temp" "$env_file"
  rm -f "$temp"
done

echo "Ajoutez ce compte dans votre application TOTP :"
echo "otpauth://totp/Demineur:${admin_user}?secret=${secret}&issuer=Demineur"
echo "Conservez le secret dans votre gestionnaire de mots de passe, puis testez la connexion avant de fermer votre session actuelle."
